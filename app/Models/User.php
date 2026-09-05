<?php

namespace App\Models;

use App\Services\CloudApiService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'password',
        'telefono',
        'direccion',
        'logo_path',
        'is_exempt',
        'recordatorio_automatico',
        'confirmacion_automatica',
        'hora_recordatorio',
        'sena_monto',
        'whatsapp_pide_sena',
        'whatsapp_sena_titular',
        'whatsapp_sena_entidad',
        'whatsapp_sena_alias',
        'whatsapp_sena_cbu',
        'fcm_token',
        'notificaciones_vistas_at',
        'debe_cambiar_password',
        'locale',
        'categorias_gasto',
        'categorias_ingreso',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'fcm_token',
        'logo_path',
    ];

    protected $appends = [
        'whatsapp_requiere_envio_manual',
        'logo_url',
        // Forzadas al JSON aunque la columna sea null (accessor resuelve al
        // set de fábrica) o aunque el modelo recién creado todavía no la
        // tenga en $attributes. El frontend siempre espera un array.
        'categorias_gasto',
        'categorias_ingreso',
    ];

    protected $attributes = [
        'recordatorio_automatico' => false,
        'confirmacion_automatica' => true,
        'hora_recordatorio'       => '20:00',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'       => 'datetime',
            'password'                => 'hashed',
            'is_exempt'               => 'boolean',
            'recordatorio_automatico' => 'boolean',
            'confirmacion_automatica' => 'boolean',
            'sena_monto'              => 'decimal:2',
            'whatsapp_pide_sena'      => 'boolean',
            'debe_cambiar_password'   => 'boolean',
            'notificaciones_vistas_at' => 'datetime',
        ];
    }

    // ── Slug automático ──────────────────────────────────────────
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->slug)) {
                $user->slug = static::generateSlug($user->name);
            }
        });
    }

    protected static function generateSlug(string $name): string
    {
        $slug = Str::slug($name);
        $original = $slug;
        $count = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$original}-{$count}";
            $count++;
        }

        return $slug;
    }

    // URL pública del logo del negocio, o null si todavía no subió uno.
    // logo_path nunca se expone directo (es una ruta relativa del disco
    // 'public'), mismo criterio que Profesional::fondoHistoriaUrl.
    protected function logoUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->logo_path
                ? Storage::disk('public')->url($this->logo_path)
                : null,
        );
    }

    // ── Categorías personalizadas de gastos / ingresos ───────────
    // Cada usuario guarda su propio set como columna JSON plana (mismo
    // criterio que recordatorio_automatico, locale, etc.). La columna es
    // nullable: null significa "nunca lo personalizó" y al LEER resuelve
    // al set de fábrica (Gasto::CATEGORIAS / Ingreso::CATEGORIAS) — el
    // default es el set inicial, no el único posible. El frontend siempre
    // recibe un array concreto, nunca null.
    //
    // Se usan accessor+mutator (no un cast 'array') justamente porque la
    // lectura tiene que resolver el default cuando la columna es null.
    //
    // El get lee siempre del raw $attributes (no del $value que recibe): al
    // estar la key en $appends, Eloquent invoca el accessor con $value=null
    // durante la serialización aunque la columna tenga datos — leer de
    // $attributes evita ese falso null.
    protected function categoriasGasto(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => self::resolverCategorias(
                $attributes['categorias_gasto'] ?? null,
                Gasto::CATEGORIAS,
            ),
            set: fn ($value) => self::codificarCategorias($value),
        );
    }

    protected function categoriasIngreso(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => self::resolverCategorias(
                $attributes['categorias_ingreso'] ?? null,
                Ingreso::CATEGORIAS,
            ),
            set: fn ($value) => self::codificarCategorias($value),
        );
    }

    private static function resolverCategorias(?string $value, array $default): array
    {
        if ($value === null) {
            return $default;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) && $decoded !== [] ? array_values($decoded) : $default;
    }

    private static function codificarCategorias($value): ?string
    {
        return $value === null ? null : json_encode(array_values($value));
    }

    // ── Relaciones ───────────────────────────────────────────────
    public function subscription()
    {
        return $this->hasOne(Subscription::class)->latest();
    }

    // Mismo criterio que CheckSubscription (middleware) — cuentas exentas
    // nunca están vencidas; sin suscripción o con ends_at pasado, sí. Lo
    // usan además los envíos automáticos de WhatsApp (EnviarMensajeConfirmacion,
    // EnviarRecordatorios): cada mensaje de Cloud API tiene costo real, no
    // hay que seguir mandándolos si la cuenta no pagó.
    public function suscripcionVencida(): bool
    {
        if ($this->is_exempt) {
            return false;
        }

        $subscription = $this->subscription;

        if (! $subscription) {
            return true;
        }

        // SUSPENDIDO es el único status no derivable de ends_at: una cuenta
        // suspendida por el admin queda cortada aunque le queden días.
        return $subscription->status === 'SUSPENDIDO' || $subscription->ends_at < now();
    }

    public function servicios()
    {
        return $this->hasMany(Servicio::class);
    }

    public function clientes()
    {
        return $this->hasMany(Cliente::class);
    }

    public function turnos()
    {
        return $this->hasMany(Turno::class);
    }

    public function slotsDisponibles()
    {
        return $this->hasMany(SlotDisponible::class);
    }

    public function profesionales()
    {
        return $this->hasMany(Profesional::class);
    }

    public function gastos()
    {
        return $this->hasMany(Gasto::class);
    }

    public function ingresos()
    {
        return $this->hasMany(Ingreso::class);
    }

    public function reservasWeb()
    {
        return $this->hasMany(ReservaWeb::class);
    }

    public function mpCredentials()
    {
        return $this->hasOne(UserMpCredential::class);
    }

    public function whatsappConnection(): HasOne
    {
        return $this->hasOne(WhatsappConnection::class);
    }

    public function whatsappMensajes()
    {
        return $this->hasMany(WhatsappMensaje::class);
    }

    /**
     * Invariante de credenciales (§diseño): devuelve credenciales
     * compartidas (nulls) SI Y SOLO SI no existe whatsappConnection. Una
     * fila existente SIEMPRE devuelve el token/número propio del negocio,
     * incluso si está expirada — estructuralmente imposible facturarle a
     * Turnetto el envío de un negocio conectado. El caso "expirada" no cae
     * al número compartido: el gate de criterioRequiereEnvioManualWhatsapp()
     * corta el envío antes de que estas credenciales se usen para mandar
     * nada; esta llamada solo deja constancia en el log.
     *
     * @return array{token: ?string, phone_number_id: ?string, provider: string}
     */
    public function credencialesWhatsapp(): array
    {
        $conexion = $this->whatsappConnection;

        if ($conexion === null) {
            return ['token' => null, 'phone_number_id' => null, 'provider' => 'cloud_api'];
        }

        if (! $conexion->estaVigente()) {
            Log::warning('whatsapp.tenant.token_expirado_en_envio', ['user_id' => $this->id]);
        }

        return [
            'token' => $conexion->access_token,
            'phone_number_id' => $conexion->phone_number_id,
            'provider' => 'cloud_api_tenant',
        ];
    }

    // ── Envío manual de confirmación (fallback) ────────────────────
    // Cloud API no tiene concepto de "conexión" — el flag es true por
    // cualquiera de estas razones: (a) falta el teléfono de contacto de la
    // profesional (no se puede armar la variable {{6}} de la plantilla),
    // (b) falta la dirección de la profesional (también es un parámetro
    // de la plantilla — si falta, Meta rechaza el envío completo), (c) el
    // locale es pt-BR (no hay plantilla aprobada en portugués todavía),
    // (d) el número que este negocio usa para enviar (propio si tiene
    // whatsappConnection, compartido si no) está señalado como no
    // saludable por Meta (ver CloudApiService::estaSaludable — señal
    // cacheada, actualizada por webhook, por número), o (e) el negocio
    // tiene whatsappConnection propia pero su token está expirado — nunca
    // cae al número compartido, falla en voz alta. En cualquiera de los
    // cinco casos, el frontend ofrece mandar el mensaje a mano por wa.me
    // en vez de depender del envío automático.
    public function getWhatsappRequiereEnvioManualAttribute(): bool
    {
        return $this->criterioRequiereEnvioManualWhatsapp();
    }

    protected function criterioRequiereEnvioManualWhatsapp(): bool
    {
        if (empty(trim($this->telefono ?? ''))) {
            return true;
        }

        if (empty(trim($this->direccion ?? ''))) {
            return true;
        }

        if ($this->locale === 'pt-BR') {
            return true;
        }

        if ($this->whatsappConnection !== null && ! $this->whatsappConnection->estaVigente()) {
            return true;
        }

        return ! app(CloudApiService::class)->estaSaludable($this->whatsappConnection?->phone_number_id);
    }
}
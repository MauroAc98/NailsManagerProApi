<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
        'fcm_token',
        'notificaciones_vistas_at',
        'debe_cambiar_password',
        'locale',
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

        return ! $subscription || $subscription->ends_at < now();
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

    public function reservasWeb()
    {
        return $this->hasMany(ReservaWeb::class);
    }

    public function mpCredentials()
    {
        return $this->hasOne(UserMpCredential::class);
    }

    public function whatsappMensajes()
    {
        return $this->hasMany(WhatsappMensaje::class);
    }

    // ── Envío manual de confirmación (fallback) ────────────────────
    // Cloud API no tiene concepto de "conexión" — el flag es true por
    // cualquiera de estas razones: (a) falta el teléfono de contacto de la
    // profesional (no se puede armar la variable {{6}} de la plantilla),
    // (b) el locale es pt-BR (no hay plantilla aprobada en portugués
    // todavía), o (c) el ratio de mensajes fallidos de Cloud API es alto.
    // En cualquiera de los tres casos, el frontend ofrece mandar el
    // mensaje a mano por wa.me en vez de depender del envío automático.
    public function getWhatsappRequiereEnvioManualAttribute(): bool
    {
        return $this->criterioRequiereEnvioManualWhatsapp();
    }

    protected function criterioRequiereEnvioManualWhatsapp(): bool
    {
        if (empty($this->telefono)) {
            return true;
        }

        if ($this->locale === 'pt-BR') {
            return true;
        }

        $desde = now()->subDays(30);

        // Filtrado por provider='cloud_api': la ventana de 30 días todavía
        // puede contener mensajes históricos de Evolution del corte
        // reciente — sin este filtro, fallos viejos de un proveedor que ya
        // no existe diluirían (o inflarían) el ratio real de Cloud API.
        $total = $this->whatsappMensajes()
            ->where('provider', 'cloud_api')
            ->where('created_at', '>=', $desde)
            ->where('status', '!=', 'manual')
            ->count();

        if ($total < 5) {
            return false;
        }

        $fallidos = $this->whatsappMensajes()
            ->where('provider', 'cloud_api')
            ->where('created_at', '>=', $desde)
            ->where('status', 'failed')
            ->count();

        return ($fallidos / $total) >= 0.03;
    }
}
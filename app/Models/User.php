<?php

namespace App\Models;

use App\Services\CloudApiService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'whatsapp_pide_sena',
        'whatsapp_sena_titular',
        'whatsapp_sena_entidad',
        'whatsapp_sena_alias',
        'whatsapp_sena_cbu',
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

    // ── Envío manual de confirmación (fallback) ────────────────────
    // Cloud API no tiene concepto de "conexión" — el flag es true por
    // cualquiera de estas razones: (a) falta el teléfono de contacto de la
    // profesional (no se puede armar la variable {{6}} de la plantilla),
    // (b) falta la dirección de la profesional (también es un parámetro
    // de la plantilla — si falta, Meta rechaza el envío completo), (c) el
    // locale es pt-BR (no hay plantilla aprobada en portugués todavía), o
    // (d) el número de Cloud API compartido está señalado como no
    // saludable por Meta (ver CloudApiService::estaSaludable — señal
    // cacheada, actualizada por webhook, global a todas las cuentas que
    // comparten el número). En cualquiera de los cuatro casos, el
    // frontend ofrece mandar el mensaje a mano por wa.me en vez de
    // depender del envío automático.
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

        return ! app(CloudApiService::class)->estaSaludable();
    }
}
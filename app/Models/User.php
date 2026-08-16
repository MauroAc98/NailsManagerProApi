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
        'hora_recordatorio',
        'sena_monto',
        'fcm_token',
        'debe_cambiar_password',
        'evolution_instance_name',
        'whatsapp_estado',
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
        'whatsapp_estado'         => 'desconectado',
        'recordatorio_automatico' => false,
        'hora_recordatorio'       => '20:00',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'       => 'datetime',
            'password'                => 'hashed',
            'is_exempt'               => 'boolean',
            'recordatorio_automatico' => 'boolean',
            'sena_monto'              => 'decimal:2',
            'debe_cambiar_password'   => 'boolean',
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

    // ── Helpers ──────────────────────────────────────────────────
    public function tieneWhatsappConectado(): bool
    {
        return $this->whatsapp_estado === 'conectado'
            && !empty($this->evolution_instance_name);
    }

    public function tieneRecordatorioAutomatico(): bool
    {
        return $this->recordatorio_automatico && $this->tieneWhatsappConectado();
    }

    // ── Relaciones ───────────────────────────────────────────────
    public function subscription()
    {
        return $this->hasOne(Subscription::class)->latest();
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

    public function whatsappEstadoHistoriales()
    {
        return $this->hasMany(WhatsappEstadoHistorial::class);
    }

    public function whatsappMensajes()
    {
        return $this->hasMany(WhatsappMensaje::class);
    }

    // ── Envío manual de confirmación (fallback) ────────────────────
    // "conectado" solo dice que el socket de WhatsApp está vivo, no que
    // WhatsApp vaya a entregar lo que se manda por ahí. Este flag es true
    // por cualquiera de estas razones: (a) la profesional nunca vinculó
    // ninguna instancia de WhatsApp (evolution_instance_name vacío — el
    // caso más común, no un edge case), (b) hay instancia vinculada pero
    // tuvo una desconexión terminal reciente sin una reconexión posterior,
    // o (c) el ratio de mensajes fallidos es alto. En cualquiera de los
    // tres casos, el frontend ofrece mandar la confirmación a mano por
    // wa.me en vez de depender del envío automático.
    public function getWhatsappRequiereEnvioManualAttribute(): bool
    {
        return $this->criterioRequiereEnvioManualWhatsapp();
    }

    protected function criterioRequiereEnvioManualWhatsapp(): bool
    {
        if (empty($this->evolution_instance_name)) {
            return true;
        }

        $desde = now()->subDays(30);

        // No alcanza con "hubo una desconexión terminal en la ventana": si
        // ya se reconectó después de esa desconexión, el blip quedó curado
        // y no debe dejar el flag pegado en true por los 30 días restantes.
        $ultimaDesconexionTerminal = $this->whatsappEstadoHistoriales()
            ->where('estado', 'desconectado')
            ->whereIn('status_reason', [401, 408, 428, 440, 515])
            ->where('created_at', '>=', $desde)
            ->latest('created_at')
            ->first();

        if ($ultimaDesconexionTerminal) {
            $seReconectoDespues = $this->whatsappEstadoHistoriales()
                ->where('estado', 'conectado')
                ->where('created_at', '>', $ultimaDesconexionTerminal->created_at)
                ->exists();

            if (! $seReconectoDespues) {
                return true;
            }
        }

        // Los envíos manuales (fallback wa.me, ver marcarRecordatorioManual)
        // no dicen nada sobre si el envío AUTOMÁTICO funciona — mezclarlos
        // acá diluiría el ratio real y podría "curar" el flag antes de
        // tiempo solo porque la profesional usó el fallback, sin que el
        // WhatsApp automático haya mejorado en nada.
        $total = $this->whatsappMensajes()
            ->where('created_at', '>=', $desde)
            ->where('status', '!=', 'manual')
            ->count();

        if ($total < 5) {
            return false;
        }

        $fallidos = $this->whatsappMensajes()
            ->where('created_at', '>=', $desde)
            ->where('status', 'failed')
            ->count();

        return ($fallidos / $total) >= 0.03;
    }
}
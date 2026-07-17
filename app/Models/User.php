<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
        'is_exempt',
        'recordatorio_automatico',
        'hora_recordatorio',
        'sena_monto',
        'fcm_token',
        'debe_cambiar_password',
        'evolution_instance_name',
        'whatsapp_estado',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'fcm_token',
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
}
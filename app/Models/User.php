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
        'activo',
        'fecha_vencimiento',
        'confirmacion_automatica',
        'sena_monto',
        'fcm_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'fcm_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'       => 'datetime',
            'password'                => 'hashed',
            'activo'                  => 'boolean',
            'confirmacion_automatica' => 'boolean',
            'fecha_vencimiento'       => 'date',
            'sena_monto'              => 'decimal:2',
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

    // ── Relaciones ───────────────────────────────────────────────
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
}
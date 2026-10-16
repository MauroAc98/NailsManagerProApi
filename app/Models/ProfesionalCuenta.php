<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

// Identidad del portal de profesionales: disjunta de User (tenant) y de
// AdminUser. Una cuenta puede estar vinculada a profesionales de varios
// negocios (ver ProfesionalVinculo). NO debe extender User ni AdminUser: el
// guard `profesional` se pinea a este modelo en config/auth.php.
class ProfesionalCuenta extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'profesional_cuentas';

    protected $fillable = [
        'email',
        'password',
        'activo',
        'debe_cambiar_password',
    ];

    // Mismos defaults que las columnas: una cuenta recien creada en memoria
    // (sin recargar de la DB) tiene que contar como activa y provisoria.
    protected $attributes = [
        'activo'                => true,
        'debe_cambiar_password' => true,
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password'              => 'hashed',
            'activo'                => 'boolean',
            'debe_cambiar_password' => 'boolean',
            'ultimo_login_at'       => 'datetime',
        ];
    }

    // El unique de Postgres distingue mayusculas: se normaliza al escribir.
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtolower(trim($value)),
        );
    }

    public function vinculos(): HasMany
    {
        return $this->hasMany(ProfesionalVinculo::class, 'cuenta_id');
    }
}

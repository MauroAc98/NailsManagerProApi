<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Union entre una ProfesionalCuenta y una Profesional de un negocio. El token
// pertenece a la cuenta; el negocio activo se resuelve siempre a traves de este
// vinculo, nunca desde datos que mande el cliente.
class ProfesionalVinculo extends Model
{
    protected $table = 'profesional_vinculos';

    protected $fillable = [
        'cuenta_id',
        'profesional_id',
        'activo',
        'invitado_por_user_id',
        'cuenta_creada_aqui',
    ];

    // Mismo default que la columna (ver ProfesionalCuenta::$attributes).
    protected $attributes = [
        'activo' => true,
        'cuenta_creada_aqui' => false,
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'cuenta_creada_aqui' => 'boolean',
        ];
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * Whether the account behind this link was created by this link's own
     * invitation AND has already completed its first login. That is the only
     * case the persona titular is allowed to learn about: for any account that
     * already existed, the first login is never reported as done.
     */
    public function primerIngresoCompletado(): bool
    {
        return $this->cuenta_creada_aqui && ! $this->cuenta->debe_cambiar_password;
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(ProfesionalCuenta::class, 'cuenta_id');
    }

    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    public function invitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitado_por_user_id');
    }
}

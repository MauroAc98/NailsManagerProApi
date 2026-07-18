<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profesional extends Model
{
    protected $table = 'profesionales';

    protected $fillable = [
        'user_id',
        'nombre',
        'color',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    // ── Scope de seguridad ───────────────────────────────────────
    public function scopeDelUsuario($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    // ─────────────────────────────────────────────
    // Resuelve el Profesional a usar para una request.
    // Si se manda un id explícito, lo valida contra la cuenta (404 si no
    // existe o es de otro usuario). Si se omite, resuelve al profesional
    // default de la cuenta (el más antiguo) — este es el mecanismo de
    // backward-compat: la app RN nunca manda profesional_id.
    // ─────────────────────────────────────────────
    public static function resolverParaUsuario(User $user, ?int $profesionalIdSolicitado): ?self
    {
        if ($profesionalIdSolicitado) {
            return $user->profesionales()->findOrFail($profesionalIdSolicitado);
        }

        return static::where('user_id', $user->id)->oldest('id')->first();
    }

    // ── Relaciones ───────────────────────────────────────────────
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function servicios()
    {
        return $this->belongsToMany(Servicio::class, 'profesional_servicio');
    }

    public function slotsDisponibles()
    {
        return $this->hasMany(SlotDisponible::class);
    }

    public function turnos()
    {
        return $this->hasMany(Turno::class);
    }
}

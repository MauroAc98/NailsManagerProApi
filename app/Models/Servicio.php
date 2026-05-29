<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Servicio extends Model
{
    protected $fillable = [
        'user_id',
        'nombre',
        'duracion_minutos',
        'precio',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo'           => 'boolean',
            'duracion_minutos' => 'integer',
            'precio'           => 'decimal:2',
        ];
    }

    // ── Scope de seguridad ───────────────────────────────────────
    public function scopeDelUsuario($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    // ── Relaciones ───────────────────────────────────────────────
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function turnos()
    {
        return $this->belongsToMany(Turno::class, 'turno_servicio');
    }
}
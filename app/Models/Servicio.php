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
        'es_promo',
        'orden',
        'categoria_id',
    ];

    protected function casts(): array
    {
        return [
            'activo'           => 'boolean',
            'duracion_minutos' => 'integer',
            'precio'           => 'decimal:2',
            'es_promo'         => 'boolean',
            'orden'            => 'integer',
            'categoria_id'     => 'integer',
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
        return $this->belongsToMany(Turno::class, 'turno_servicio')->withPivot('precio');
    }

    public function profesionales()
    {
        return $this->belongsToMany(Profesional::class, 'profesional_servicio');
    }

    public function categoria()
    {
        return $this->belongsTo(CategoriaServicio::class, 'categoria_id');
    }
}
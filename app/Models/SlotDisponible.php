<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlotDisponible extends Model
{
    protected $table = 'slots_disponibles';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'profesional_id',
        'hora',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'hora'   => 'string',
        ];
    }

    // ── Scope de seguridad ───────────────────────────────────────
    public function scopeDelUsuario($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    // ── Scope de activos ─────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    // ── Relaciones ───────────────────────────────────────────────
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function profesional()
    {
        return $this->belongsTo(Profesional::class);
    }
}

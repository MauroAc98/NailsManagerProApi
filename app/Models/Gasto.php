<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gasto extends Model
{
    public const CATEGORIAS = ['insumos', 'alquiler', 'servicios_publicos', 'marketing', 'otros'];

    protected $fillable = [
        'user_id',
        'profesional_id',
        'fecha',
        'monto',
        'categoria',
        'descripcion',
    ];

    protected function casts(): array
    {
        return [
            'fecha'          => 'date:Y-m-d',
            'monto'          => 'decimal:2',
            'profesional_id' => 'integer',
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

    public function profesional()
    {
        return $this->belongsTo(Profesional::class);
    }
}

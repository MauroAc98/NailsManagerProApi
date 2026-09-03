<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ingreso extends Model
{
    public const CATEGORIAS = ['venta_productos', 'alquiler_espacio', 'otros'];

    protected $fillable = [
        'user_id',
        'fecha',
        'monto',
        'categoria',
        'descripcion',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'monto' => 'decimal:2',
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
}

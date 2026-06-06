<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReservaWeb extends Model
{
    protected $table = 'reservas_web';
    protected $fillable = [
        'user_id',
        'nombre_completo',
        'telefono',
        'servicio_ids',
        'fecha',
        'slot_hora',
        'duracion_total_minutos',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'servicio_ids'           => 'array',
            'fecha'                  => 'date',
            'duracion_total_minutos' => 'integer',
        ];
    }

    // ── Scopes de seguridad ──────────────────────────────────────
    public function scopeDelUsuario($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopePendientes($query)
    {
        return $query->where('estado', 'pending_payment');
    }

    public function scopeDelaFecha($query, string $fecha)
    {
        return $query->where('fecha', $fecha);
    }

    // ── Relaciones ───────────────────────────────────────────────
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pagoSena()
    {
        return $this->hasOne(PagoSena::class);
    }

    public function turno()
    {
        return $this->hasOne(Turno::class);
    }
}

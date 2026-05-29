<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagoSena extends Model
{
    protected $fillable = [
        'reserva_web_id',
        'mp_preference_id',
        'mp_payment_id',
        'monto',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
        ];
    }

    // ── Helpers de estado ────────────────────────────────────────
    public function estaAprobado(): bool
    {
        return $this->estado === 'aprobado';
    }

    public function estaPendiente(): bool
    {
        return $this->estado === 'pendiente';
    }

    // ── Relaciones ───────────────────────────────────────────────
    public function reservaWeb()
    {
        return $this->belongsTo(ReservaWeb::class);
    }
}
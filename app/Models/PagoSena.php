<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagoSena extends Model
{
    // Sin esto, Eloquent adivina 'pago_senas' (plural de "sena", no del
    // compuesto): la tabla real es 'pagos_sena' (create_pagos_sena_table).
    // Nunca se habia detectado porque nada llegaba a tocar este modelo contra
    // la base todavia (el webhook viejo esta roto y sin tests que la usen).
    protected $table = 'pagos_sena';

    protected $fillable = [
        'reserva_web_id',
        'mp_preference_id',
        'mp_payment_id',
        'init_point',
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
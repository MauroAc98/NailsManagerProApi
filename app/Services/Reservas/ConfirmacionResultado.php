<?php

namespace App\Services\Reservas;

use App\Models\Turno;

/**
 * Resultado de ConfirmarReservaService::confirmar():
 * - confirmed: se creo el turno.
 * - already_confirmed: idempotente, devuelve el turno existente.
 * - needs_refund: el pago no puede convertirse en turno (motivo); la reserva
 *   queda con requiere_reembolso = true para el flujo de reembolso posterior.
 */
class ConfirmacionResultado
{
    public const CONFIRMED = 'confirmed';
    public const ALREADY_CONFIRMED = 'already_confirmed';
    public const NEEDS_REFUND = 'needs_refund';

    public function __construct(
        public readonly string $resultado,
        public readonly ?Turno $turno = null,
        public readonly ?string $motivo = null,
    ) {
    }
}

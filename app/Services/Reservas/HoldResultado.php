<?php

namespace App\Services\Reservas;

use App\Models\ReservaWeb;

/** Resultado de una operacion de HoldService. $replay = respuesta idempotente de una key ya usada. */
class HoldResultado
{
    public function __construct(
        public readonly ReservaWeb $reserva,
        public readonly bool $replay = false,
    ) {
    }
}

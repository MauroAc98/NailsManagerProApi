<?php

namespace App\Services\Reservas;

/**
 * Servicios sueltos (no promo) de UNA profesional elegida por la clienta, como
 * un solo tramo. $duracionMinutos = suma de las duraciones de sus servicios.
 */
final class GrupoSuelto
{
    /** @param  array<int, int>  $servicioIds */
    public function __construct(
        public readonly int $profesionalId,
        public readonly array $servicioIds,
        public readonly int $duracionMinutos,
    ) {
    }
}

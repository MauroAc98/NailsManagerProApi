<?php

namespace App\Services\Reservas;

/**
 * Un plan candidato de reserva: tramos ordenados, cada uno con una profesional
 * fija y tiempos como OFFSETS en minutos desde el inicio S (sin fechas ni casts
 * de datetime). El shape de cada tramo coincide con reservas_web.tramos.
 *
 * @phpstan-type Tramo array{profesional_id: int, offset_minutos: int, duracion_minutos: int, servicio_ids: array<int, int>, precio_sugerido: int|null}
 */
final class PlanReserva
{
    public const PARALELO = 'paralelo';
    public const SECUENCIA = 'secuencia';

    /** @param  array<int, array>  $tramos */
    public function __construct(
        public readonly string $modo,
        public readonly array $tramos,
    ) {
    }

    /** Duracion total del turno: paralelo = max de los tramos, secuencia = suma (fin del ultimo). */
    public function duracionTotalMinutos(): int
    {
        return $this->tramos === []
            ? 0
            : max(array_map(fn (array $t) => $t['offset_minutos'] + $t['duracion_minutos'], $this->tramos));
    }
}

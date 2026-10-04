<?php

namespace App\Services\Reservas;

use App\Models\ReservaWeb;
use App\Models\Servicio;

/**
 * Total (en pesos) de una reserva online: la base del porcentaje de seña.
 *
 * - Legacy (sin tramos): suma de Servicio.precio de servicio_ids.
 * - Plan (tramos): suma de cada tramo.precio_sugerido (los tramos de una promo
 *   llevan el precio de la promo prorrateado); un tramo sin precio_sugerido
 *   (grupo suelto) suma el Servicio.precio de sus servicios. NUNCA se suman los
 *   precios de servicio_ids cuando hay tramos: en una promo darian los precios
 *   sueltos de los componentes y no el de la promo.
 *
 * Precio null cuenta como 0. Los servicios se leen acotados al salon de la
 * reserva. 0 = total indeterminado: quien llama decide que hacer.
 */
class TotalReserva
{
    public function de(ReservaWeb $reserva): float
    {
        $tramos = $reserva->tramos;

        if (! is_array($tramos) || $tramos === []) {
            return $this->precioDeServicios((int) $reserva->user_id, $reserva->servicio_ids ?? []);
        }

        $total = 0.0;
        foreach ($tramos as $tramo) {
            $total += isset($tramo['precio_sugerido'])
                ? (float) $tramo['precio_sugerido']
                : $this->precioDeServicios((int) $reserva->user_id, $tramo['servicio_ids'] ?? []);
        }

        return $total;
    }

    /** @param  array<int, int|string>  $servicioIds */
    private function precioDeServicios(int $userId, array $servicioIds): float
    {
        if ($servicioIds === []) {
            return 0.0;
        }

        return (float) Servicio::where('user_id', $userId)
            ->whereIn('id', array_map('intval', $servicioIds))
            ->sum('precio');
    }
}

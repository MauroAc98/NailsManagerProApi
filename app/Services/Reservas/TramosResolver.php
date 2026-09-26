<?php

namespace App\Services\Reservas;

/**
 * Convierte una reserva (promo componentizada opcional + grupos de servicios
 * sueltos) en planes candidatos. PURO: sin DB, sin locks, sin Eloquent.
 * `[]` = input legacy (una sola profesional / promo sin componentes): la logica
 * legacy corre sin cambios.
 */
class TramosResolver
{
    /**
     * @param  array<int, GrupoSuelto>  $grupos  en el orden en que la clienta los eligio
     * @return array<int, PlanReserva>
     */
    public function planes(?PromoInput $promo, array $grupos, bool $paraleloHabilitado): array
    {
        return [];
    }
}

<?php

namespace App\Services\Reservas;

use InvalidArgumentException;

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
        if ($promo !== null && $promo->componentes !== []) {
            return [$this->planPromo($promo)];
        }

        return [];
    }

    private function planPromo(PromoInput $promo): PlanReserva
    {
        $paralelo = $promo->modo === PlanReserva::PARALELO;
        $profesionales = array_column($promo->componentes, 'profesional_id');
        if ($paralelo && count(array_unique($profesionales)) !== count($profesionales)) {
            throw new InvalidArgumentException('Una promo en paralelo exige profesionales distintas.');
        }

        $tramos = [];
        $offset = 0;
        foreach ($promo->componentes as $componente) {
            $tramos[] = $this->tramo(
                $componente['profesional_id'],
                $paralelo ? 0 : $offset,
                $componente['duracion_minutos'],
                [$componente['servicio_id']],
                $componente['precio'],
            );
            $offset += $componente['duracion_minutos'];
        }

        return new PlanReserva($promo->modo, $tramos);
    }

    private function tramo(int $profesionalId, int $offset, int $duracion, array $servicioIds, ?int $precio): array
    {
        return [
            'profesional_id' => $profesionalId,
            'offset_minutos' => $offset,
            'duracion_minutos' => $duracion,
            'servicio_ids' => $servicioIds,
            'precio_sugerido' => $precio,
        ];
    }
}

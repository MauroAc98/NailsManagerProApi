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
            return [$this->conSueltosAlFinal($this->planPromo($promo), $grupos)];
        }

        // Una sola profesional (o ninguna) sin promo es legacy: no hay plan.
        if (count($grupos) < 2) {
            return [];
        }

        return $this->planesSueltos($grupos, $paraleloHabilitado);
    }

    /**
     * Grupos sueltos sin promo: paralelo primero (solo si el estudio atiende en
     * paralelo y las profesionales son todas distintas), secuencia siempre.
     *
     * @param  array<int, GrupoSuelto>  $grupos
     * @return array<int, PlanReserva>
     */
    private function planesSueltos(array $grupos, bool $paraleloHabilitado): array
    {
        $profesionales = array_map(fn (GrupoSuelto $g) => $g->profesionalId, $grupos);
        $planes = [];
        if ($paraleloHabilitado && count(array_unique($profesionales)) === count($profesionales)) {
            $planes[] = new PlanReserva(PlanReserva::PARALELO, array_map(
                fn (GrupoSuelto $g) => $this->tramo($g->profesionalId, 0, $g->duracionMinutos, $g->servicioIds, null),
                $grupos,
            ));
        }
        $planes[] = new PlanReserva(PlanReserva::SECUENCIA, $this->fusionar($this->tramosEnSecuencia($grupos, 0)));

        return $planes;
    }

    /**
     * @param  array<int, GrupoSuelto>  $grupos
     * @return array<int, array>
     */
    private function tramosEnSecuencia(array $grupos, int $offset): array
    {
        $tramos = [];
        foreach ($grupos as $grupo) {
            $tramos[] = $this->tramo($grupo->profesionalId, $offset, $grupo->duracionMinutos, $grupo->servicioIds, null);
            $offset += $grupo->duracionMinutos;
        }

        return $tramos;
    }

    /**
     * Con promo hay UN solo plan (el modo de la promo): los sueltos van en
     * secuencia despues del fin de la promo.
     *
     * @param  array<int, GrupoSuelto>  $grupos
     */
    private function conSueltosAlFinal(PlanReserva $plan, array $grupos): PlanReserva
    {
        if ($grupos === []) {
            return $plan;
        }

        return new PlanReserva($plan->modo, $this->fusionar([
            ...$plan->tramos,
            ...$this->tramosEnSecuencia($grupos, $plan->duracionTotalMinutos()),
        ]));
    }

    private function planPromo(PromoInput $promo): PlanReserva
    {
        $paralelo = $promo->modo === PlanReserva::PARALELO;
        $profesionales = array_column($promo->componentes, 'profesional_id');
        if ($paralelo && count(array_unique($profesionales)) !== count($profesionales)) {
            throw new InvalidArgumentException('Una promo en paralelo exige profesionales distintas.');
        }

        $precios = $this->prorratear($promo);
        $tramos = [];
        $offset = 0;
        foreach ($promo->componentes as $i => $componente) {
            $tramos[] = $this->tramo(
                $componente['profesional_id'],
                $paralelo ? 0 : $offset,
                $componente['duracion_minutos'],
                [$componente['servicio_id']],
                $precios[$i],
            );
            $offset += $componente['duracion_minutos'];
        }

        return new PlanReserva($promo->modo, $this->fusionar($tramos));
    }

    /**
     * Un tramo cuyo offset coincide con el fin de un tramo anterior de la MISMA
     * profesional lo extiende: duraciones sumadas, servicios concatenados y
     * precios sugeridos no nulos sumados. En paralelo (offsets en 0) nunca fusiona.
     *
     * @param  array<int, array>  $tramos
     * @return array<int, array>
     */
    private function fusionar(array $tramos): array
    {
        $fusionados = [];
        foreach ($tramos as $tramo) {
            foreach ($fusionados as $i => $previo) {
                if ($previo['profesional_id'] === $tramo['profesional_id']
                    && $previo['offset_minutos'] + $previo['duracion_minutos'] === $tramo['offset_minutos']) {
                    $sinPrecio = [...($previo['servicios_sin_precio'] ?? ($previo['precio_sugerido'] === null ? $previo['servicio_ids'] : [])),
                        ...($tramo['servicios_sin_precio'] ?? ($tramo['precio_sugerido'] === null ? $tramo['servicio_ids'] : []))];
                    $fusionados[$i]['duracion_minutos'] += $tramo['duracion_minutos'];
                    $fusionados[$i]['servicio_ids'] = [...$previo['servicio_ids'], ...$tramo['servicio_ids']];
                    $fusionados[$i]['precio_sugerido'] = $previo['precio_sugerido'] === null && $tramo['precio_sugerido'] === null
                        ? null
                        : ($previo['precio_sugerido'] ?? 0) + ($tramo['precio_sugerido'] ?? 0);
                    // Pieza con precio + pieza sin precio: el precio sugerido no incluye a
                    // estos servicios; se anotan para que TotalReserva los sume aparte.
                    if ($fusionados[$i]['precio_sugerido'] !== null && $sinPrecio !== []) {
                        $fusionados[$i]['servicios_sin_precio'] = $sinPrecio;
                    }
                    continue 2;
                }
            }
            $fusionados[] = $tramo;
        }

        return $fusionados;
    }

    /**
     * Precio del tramo = precioPromo x standalone / suma de standalone, half-up a
     * pesos enteros; el ULTIMO recibe precioPromo menos lo repartido (suma exacta).
     *
     * @return array<int, int>
     */
    private function prorratear(PromoInput $promo): array
    {
        $standalone = array_column($promo->componentes, 'precio');
        $suma = array_sum($standalone);
        $total = $promo->precioPromo ?? $suma;

        $precios = [];
        foreach (array_slice($standalone, 0, -1, true) as $i => $precio) {
            $precios[$i] = $suma > 0 ? intdiv(2 * $total * $precio + $suma, 2 * $suma) : 0;
        }
        $precios[array_key_last($standalone)] = $total - array_sum($precios);

        return $precios;
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

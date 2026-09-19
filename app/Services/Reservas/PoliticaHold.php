<?php

namespace App\Services\Reservas;

use Illuminate\Support\Carbon;

/**
 * Duraciones de hold / pago y regla de "alta ocupacion". Sin acceso a DB: los
 * datos de ocupacion llegan por parametro (ver DisponibilidadService::ocupacionDelDia).
 */
class PoliticaHold
{
    public function holdMinutos(bool $altaOcupacion): int
    {
        return (int) config($altaOcupacion ? 'reservas.hold_minutos_alta' : 'reservas.hold_minutos');
    }

    public function pagoMinutos(bool $altaOcupacion): int
    {
        return (int) config($altaOcupacion ? 'reservas.pago_minutos_alta' : 'reservas.pago_minutos');
    }

    /**
     * Alta ocupacion = de los slots activos que todavia no pasaron, la fraccion
     * cuyo INICIO cae dentro de un intervalo ocupado (turno confirmado o hold
     * vivo, semi-abierto) es >= umbral. Sin slots futuros no es alta.
     *
     * @param  array<int, string>  $horasActivas  'HH:MM' de los slots activos de la profesional
     * @param  array<int, array{0: Carbon, 1: Carbon}>  $ocupados  intervalos [inicio, fin)
     */
    public function esAlta(string $fecha, array $horasActivas, array $ocupados, Carbon $ahora): bool
    {
        $total = 0;
        $tomados = 0;

        foreach (array_unique($horasActivas) as $hora) {
            $inicio = Carbon::parse("{$fecha} {$hora}");
            if ($inicio->lt($ahora)) {
                continue;
            }
            $total++;

            foreach ($ocupados as [$desde, $hasta]) {
                if ($inicio->gte($desde) && $inicio->lt($hasta)) {
                    $tomados++;
                    break;
                }
            }
        }

        if ($total === 0) {
            return false;
        }

        return ($tomados / $total) >= (float) config('reservas.ocupacion_alta_umbral');
    }
}

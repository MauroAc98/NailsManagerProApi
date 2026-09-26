<?php

namespace App\Services\Reservas;

/**
 * Regla de slots de los flujos CLIENTA: cada tramo de un plan arranca en un
 * slot activo de SU PROPIA profesional, en ambos modos (paralelo: todos en S;
 * secuencia: cada uno cuando termina el anterior). PURO: sin DB ni Eloquent;
 * recibe los tramos de un PlanReserva (offsets en minutos desde S) y los slots
 * 'HH:MM' por profesional.
 */
class AlineacionSlots
{
    private const MINUTOS_POR_DIA = 1440;

    /**
     * Primer tramo (en orden del plan) cuyo inicio no cae en un slot de su
     * profesional; null si el inicio $hora esta alineado. Un tramo que arranca
     * al dia siguiente (cruza medianoche) nunca se alinea: los slots son horas
     * de un solo dia.
     *
     * @param  array<int, array{profesional_id: int, offset_minutos: int}>  $tramos
     * @param  array<int, array<int, string>>  $slotsPorProf  profesional_id => ['HH:MM', ...]
     * @return array{profesional_id: int, hora_requerida: string}|null
     */
    public function primerDesalineado(array $tramos, string $hora, array $slotsPorProf): ?array
    {
        $inicio = $this->aMinutos($hora);

        foreach ($tramos as $tramo) {
            $minutos = $inicio + $tramo['offset_minutos'];
            $requerida = $this->formatear($minutos % self::MINUTOS_POR_DIA);

            if ($minutos >= self::MINUTOS_POR_DIA || ! in_array($requerida, $slotsPorProf[$tramo['profesional_id']] ?? [], true)) {
                return ['profesional_id' => $tramo['profesional_id'], 'hora_requerida' => $requerida];
            }
        }

        return null;
    }

    /**
     * Analisis de configuracion de una promo: los candidatos son los slots de la
     * profesional del primer tramo; cada uno queda como inicio valido o como
     * descartado con la profesional y la hora que le falta.
     *
     * @param  array<int, array{profesional_id: int, offset_minutos: int}>  $tramos
     * @param  array<int, array<int, string>>  $slotsPorProf
     * @param  array<int, string>  $nombres  profesional_id => nombre
     * @return array{inicios_validos: array<int, string>, descartados: array<int, array{hora_inicio: string, profesional_id: int, profesional_nombre: string, hora_requerida: string, mensaje: string}>}
     */
    public function analizarPromo(array $tramos, array $slotsPorProf, array $nombres): array
    {
        $analisis = ['inicios_validos' => [], 'descartados' => []];
        if ($tramos === []) {
            return $analisis;
        }

        $candidatos = array_values(array_unique($slotsPorProf[$tramos[0]['profesional_id']] ?? []));
        sort($candidatos);

        foreach ($candidatos as $hora) {
            $desalineado = $this->primerDesalineado($tramos, $hora, $slotsPorProf);
            if ($desalineado === null) {
                $analisis['inicios_validos'][] = $hora;
                continue;
            }

            $nombre = $nombres[$desalineado['profesional_id']] ?? '';
            $analisis['descartados'][] = [
                'hora_inicio' => $hora,
                'profesional_id' => $desalineado['profesional_id'],
                'profesional_nombre' => $nombre,
                'hora_requerida' => $desalineado['hora_requerida'],
                'mensaje' => "{$nombre} no tiene slot a las {$desalineado['hora_requerida']}, esta promo no se ofrecerá a las {$hora}",
            ];
        }

        return $analisis;
    }

    private function aMinutos(string $hora): int
    {
        return (int) substr($hora, 0, 2) * 60 + (int) substr($hora, 3, 2);
    }

    private function formatear(int $minutos): string
    {
        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }
}

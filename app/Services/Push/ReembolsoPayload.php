<?php

namespace App\Services\Push;

use App\Models\ReservaWeb;
use Illuminate\Support\Carbon;

/**
 * Payload for the "client paid but got no turno" push. Same PII as the new
 * booking push (first name + last initial); never the phone number.
 */
class ReembolsoPayload
{
    /** @return array{title: string, body: string, url: string, tag: string, timestamp: int} */
    public static function para(ReservaWeb $reserva): array
    {
        $fecha = substr((string) $reserva->getRawOriginal('fecha'), 0, 10);
        $hora = substr((string) $reserva->getRawOriginal('slot_hora'), 0, 5);
        $inicio = Carbon::createFromFormat('Y-m-d', $fecha);

        $apellido = trim((string) $reserva->apellido);
        $cliente = trim(explode(' ', trim((string) $reserva->nombre))[0]
            . ($apellido === '' ? '' : ' ' . mb_strtoupper(mb_substr($apellido, 0, 1)) . '.'));
        $cliente = $cliente === '' ? 'Un cliente' : $cliente;

        $cuando = sprintf(
            '%s %d %s · %s',
            ReservaOnlinePayload::DIAS[$inicio->dayOfWeek],
            $inicio->day,
            ReservaOnlinePayload::MESES[$inicio->month - 1],
            $hora,
        );

        return [
            'title' => 'Seña cobrada sin turno',
            'body' => "{$cliente} pagó la seña pero el horario ya no estaba disponible. Reembolsala desde Mercado Pago.\n{$cuando}",
            'url' => '/agenda?fecha=' . $fecha,
            'tag' => 'reembolso-reserva-' . $reserva->id,
            'timestamp' => now()->getTimestampMs(),
        ];
    }
}

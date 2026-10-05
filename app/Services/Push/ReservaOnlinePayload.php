<?php

namespace App\Services\Push;

use App\Models\Turno;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the JSON the service worker reads for a "new online booking" push:
 * { title, body, url, tag, timestamp }.
 *
 * A reservation with several turnos (combo / grupo) is ONE push, so the
 * payload is derived from the whole group no matter which turno is passed in.
 */
class ReservaOnlinePayload
{
    public const DIAS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

    public const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /** @return array{title: string, body: string, url: string, tag: string, timestamp: int} */
    public static function para(Turno $turno): array
    {
        $turnos = self::turnosDeLaReserva($turno);
        $primero = $turnos->first();

        // Raw stored wall-clock value, NOT the datetime cast: app.timezone is not
        // UTC and the cast round-trip shifts the instant (~3h).
        $inicio = Carbon::createFromFormat('Y-m-d H:i:s', substr((string) $primero->getRawOriginal('fecha_hora'), 0, 19));

        $servicios = $turnos->flatMap(fn (Turno $t) => $t->servicios->pluck('nombre'))->unique()->values();
        $profesionales = $turnos->map(fn (Turno $t) => self::primerNombre((string) $t->profesional?->nombre))
            ->filter()->unique()->values();

        $cuando = sprintf(
            '%s %d %s · %s',
            self::DIAS[$inicio->dayOfWeek],
            $inicio->day,
            self::MESES[$inicio->month - 1],
            $inicio->format('H:i'),
        );
        if ($profesionales->isNotEmpty()) {
            $cuando .= ' con ' . self::conMas($profesionales->all());
        }

        return [
            'title' => 'Nueva reserva online',
            'body' => self::cliente($primero) . ' · ' . self::conMas($servicios->all(), ' + ') . "\n" . $cuando,
            'url' => '/agenda?fecha=' . $inicio->format('Y-m-d'),
            'tag' => $primero->reserva_web_id !== null
                ? 'reserva-online-' . $primero->reserva_web_id
                : 'reserva-online-turno-' . $primero->id,
            'timestamp' => now()->getTimestampMs(),
        ];
    }

    /** Turnos of the same reservation (grupo), earliest first. */
    private static function turnosDeLaReserva(Turno $turno): Collection
    {
        $turnos = $turno->grupo_id === null
            ? new EloquentCollection([$turno])
            : Turno::where('grupo_id', $turno->grupo_id)->get();

        $turnos->load(['servicios', 'profesional', 'cliente']);

        return $turnos
            ->sortBy(fn (Turno $t) => $t->getRawOriginal('fecha_hora') . '#' . str_pad((string) $t->id, 12, '0', STR_PAD_LEFT))
            ->values();
    }

    /** "Camila R." (first name + last initial); first name only when there is no last name. */
    private static function cliente(Turno $turno): string
    {
        $cliente = $turno->cliente;
        $nombre = self::primerNombre((string) $cliente?->nombre);
        $apellido = trim((string) $cliente?->apellido);

        return $apellido === '' ? $nombre : $nombre . ' ' . mb_strtoupper(mb_substr($apellido, 0, 1)) . '.';
    }

    private static function primerNombre(string $nombre): string
    {
        return explode(' ', trim($nombre))[0];
    }

    /** "A", or "A + 1 más" / "A y 1 más" for several items. */
    private static function conMas(array $items, string $separador = ' y '): string
    {
        $extra = count($items) - 1;

        return $extra > 0 ? $items[0] . $separador . $extra . ' más' : (string) ($items[0] ?? '');
    }
}

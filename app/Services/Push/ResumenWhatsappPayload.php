<?php

namespace App\Services\Push;

use App\Models\Turno;
use App\Models\WhatsappMensaje;

/**
 * Builds the JSON the service worker reads for the "delivered summary" push:
 * { title, body, url, tag, timestamp, silencioso }. The tag is one per
 * category and day, and `silencioso` tells the service worker to replace the
 * previous notification of that tag without alerting again, so a burst of
 * deliveries ends up as a single, quietly updated notification.
 */
class ResumenWhatsappPayload
{
    public const CATEGORIAS = ['confirmaciones', 'recordatorios'];

    /** WhatsappMensaje.tipo values counted by each category. */
    private const TIPOS = [
        'confirmaciones' => ['confirmacion', 'reprogramacion'],
        'recordatorios' => ['recordatorio'],
    ];

    private const TITULOS = [
        'confirmaciones' => 'Confirmaciones enviadas',
        'recordatorios' => 'Recordatorios enviados',
    ];

    /** @return list<string>|null null when the category is unknown */
    public static function tipos(string $categoria): ?array
    {
        return self::TIPOS[$categoria] ?? null;
    }

    /** Category that summarizes a given message tipo, or null when it has none. */
    public static function categoriaDeTipo(?string $tipo): ?string
    {
        foreach (self::TIPOS as $categoria => $tipos) {
            if (in_array($tipo, $tipos, true)) {
                return $categoria;
            }
        }

        return null;
    }

    /**
     * @param  iterable<WhatsappMensaje>  $mensajes  delivered messages of the category, most recently
     *                                               updated first, with turno.cliente and turno.servicios loaded
     * @return array{title: string, body: string, url: string, tag: string, timestamp: int, silencioso: bool}
     */
    public static function para(string $categoria, int $entregados, int $fallaron, iterable $mensajes = []): array
    {
        $turnos = self::turnosPorCliente($mensajes);

        return [
            'title' => count($turnos) > 0 ? self::titulo($categoria, count($turnos)) : self::TITULOS[$categoria],
            'body' => count($turnos) > 0 ? self::cuerpoConNombres($turnos, $fallaron) : self::cuerpo($entregados, $fallaron),
            'url' => '/agenda',
            'tag' => 'resumen-whatsapp-' . $categoria . '-' . now()->toDateString(),
            'timestamp' => now()->getTimestampMs(),
            'silencioso' => true,
        ];
    }

    private static function titulo(string $categoria, int $clientes): string
    {
        if ($clientes === 1) {
            return $categoria === 'confirmaciones' ? 'Confirmación entregada' : 'Recordatorio entregado';
        }

        return $categoria === 'confirmaciones' ? 'Confirmaciones entregadas' : 'Recordatorios entregados';
    }

    /**
     * One turno per client (the one of that client's most recent message),
     * keeping the order of the given messages. Messages whose turno or client
     * is gone, or whose client has no usable name, are ignored.
     *
     * @param  iterable<WhatsappMensaje>  $mensajes
     * @return list<Turno>
     */
    private static function turnosPorCliente(iterable $mensajes): array
    {
        $turnos = [];

        foreach ($mensajes as $mensaje) {
            $turno = $mensaje->turno;

            if (! $turno || ! $turno->cliente || isset($turnos[$turno->cliente_id])) {
                continue;
            }

            if (ReservaOnlinePayload::cliente($turno) === '') {
                continue;
            }

            $turnos[$turno->cliente_id] = $turno;
        }

        return array_values($turnos);
    }

    /** @param  non-empty-list<Turno>  $turnos */
    private static function cuerpoConNombres(array $turnos, int $fallaron): string
    {
        $clientes = count($turnos);
        $nombres = array_map(fn (Turno $t) => ReservaOnlinePayload::cliente($t), $turnos);

        if ($clientes === 1) {
            $servicios = $turnos[0]->servicios->pluck('nombre')->unique()->values()->all();
            $linea1 = $nombres[0] . ($servicios ? ' · ' . ReservaOnlinePayload::conMas($servicios, ' + ') : '');
            $linea2 = FalloWhatsappPayload::cuando($turnos[0]);
        } else {
            $linea1 = $clientes <= 3
                ? implode(', ', array_slice($nombres, 0, -1)) . ' y ' . end($nombres)
                : $nombres[0] . ', ' . $nombres[1] . ' y ' . ($clientes - 2) . ' más';
            $linea2 = $clientes . ' entregados';
        }

        return $linea1 . "\n" . $linea2 . self::sufijoFallos($fallaron);
    }

    private static function cuerpo(int $entregados, int $fallaron): string
    {
        return $entregados . ($entregados === 1 ? ' entregado' : ' entregados') . self::sufijoFallos($fallaron);
    }

    private static function sufijoFallos(int $fallaron): string
    {
        if ($fallaron === 0) {
            return '';
        }

        return $fallaron === 1 ? ' · 1 falló' : ' · ' . $fallaron . ' fallaron';
    }
}

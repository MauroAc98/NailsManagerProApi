<?php

namespace App\Services\Push;

use App\Models\WhatsappMensaje;
use Illuminate\Support\Carbon;

/**
 * Builds the JSON the service worker reads for a "WhatsApp message failed"
 * push: { title, body, url, tag, timestamp }. The url deep-links to the
 * notification detail screen, where the owner can resend it by hand.
 */
class FalloWhatsappPayload
{
    private const TITULOS = [
        'confirmacion' => 'No se pudo enviar la confirmación',
        'recordatorio' => 'No se pudo enviar el recordatorio',
        'reprogramacion' => 'No se pudo enviar el aviso de reprogramación',
    ];

    private const TITULO_GENERICO = 'No se pudo enviar un mensaje de WhatsApp';

    private const CUERPO_SIN_TURNO = 'Tocá para ver el detalle y reenviarlo a mano';

    /** @return array{title: string, body: string, url: string, tag: string, timestamp: int} */
    public static function para(WhatsappMensaje $mensaje): array
    {
        return [
            'title' => self::TITULOS[$mensaje->tipo] ?? self::TITULO_GENERICO,
            'body' => self::cuerpo($mensaje),
            'url' => '/agenda/notificaciones/' . $mensaje->id,
            'tag' => 'fallo-whatsapp-' . $mensaje->id,
            'timestamp' => now()->getTimestampMs(),
        ];
    }

    private static function cuerpo(WhatsappMensaje $mensaje): string
    {
        $turno = $mensaje->turno;

        // The turno (or its client) can be deleted after the message was sent.
        if (! $turno || ! $turno->cliente) {
            return self::CUERPO_SIN_TURNO;
        }

        // Raw stored wall-clock value, NOT the datetime cast: app.timezone is not
        // UTC and the cast round-trip shifts the instant (~3h).
        $inicio = Carbon::createFromFormat('Y-m-d H:i:s', substr((string) $turno->getRawOriginal('fecha_hora'), 0, 19));

        $servicios = $turno->servicios->pluck('nombre')->unique()->values()->all();

        $cuando = sprintf(
            '%s %d %s · %s',
            ReservaOnlinePayload::DIAS[$inicio->dayOfWeek],
            $inicio->day,
            ReservaOnlinePayload::MESES[$inicio->month - 1],
            $inicio->format('H:i'),
        );

        return ReservaOnlinePayload::cliente($turno) . ' · ' . ReservaOnlinePayload::conMas($servicios, ' + ') . "\n" . $cuando;
    }
}

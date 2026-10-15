<?php

namespace App\Services\Push;

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

    /** @return array{title: string, body: string, url: string, tag: string, timestamp: int, silencioso: bool} */
    public static function para(string $categoria, int $entregados, int $fallaron): array
    {
        return [
            'title' => self::TITULOS[$categoria],
            'body' => self::cuerpo($entregados, $fallaron),
            'url' => '/agenda',
            'tag' => 'resumen-whatsapp-' . $categoria . '-' . now()->toDateString(),
            'timestamp' => now()->getTimestampMs(),
            'silencioso' => true,
        ];
    }

    private static function cuerpo(int $entregados, int $fallaron): string
    {
        $cuerpo = $entregados . ($entregados === 1 ? ' entregado' : ' entregados');

        if ($fallaron > 0) {
            $cuerpo .= $fallaron === 1 ? ' · 1 falló' : ' · ' . $fallaron . ' fallaron';
        }

        return $cuerpo;
    }
}

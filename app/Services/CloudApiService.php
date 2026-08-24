<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudApiService
{
    public const CACHE_KEY_SALUD = 'whatsapp_cloud:salud_numero';

    private string $token = '';

    private string $phoneNumberId = '';

    private string $apiVersion = '';

    public function __construct()
    {
        $this->token = config('services.whatsapp_cloud.token') ?? '';
        $this->phoneNumberId = config('services.whatsapp_cloud.phone_number_id') ?? '';
        $this->apiVersion = config('services.whatsapp_cloud.api_version') ?? '';
    }

    private function headers(): array
    {
        return [
            'Authorization' => "Bearer {$this->token}",
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Deja solo dígitos. A diferencia de EvolutionService::normalizarNumero,
     * NO inserta el "9" de celulares argentinos — ese ajuste es un requisito
     * del protocolo WhatsApp Web/Baileys, no de la Cloud API oficial.
     * Confirmado con un envío de prueba real (piloto Testeo Dev, 2026-08-20):
     * un número guardado sin el "9" (+543764794897) entregó correctamente.
     */
    public function normalizarNumero(string $numero): string
    {
        return preg_replace('/\D/', '', $numero);
    }

    /**
     * Envía un mensaje de plantilla aprobada por Meta.
     * $parametros va en el mismo orden que las variables {{1}}, {{2}}... del cuerpo.
     */
    public function enviarPlantilla(string $numero, string $template, string $idioma, array $parametros): CloudApiEnvioResultado
    {
        $numero = $this->normalizarNumero($numero);

        $response = Http::withHeaders($this->headers())
            ->post("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $numero,
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => $idioma],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => array_map(
                            fn (string $valor) => ['type' => 'text', 'text' => $valor],
                            $parametros
                        ),
                    ]],
                ],
            ]);

        $respuesta = $response->json() ?? [];

        if (! $response->successful()) {
            Log::error('CloudApiService::enviarPlantilla falló', [
                'numero' => $numero,
                'template' => $template,
                'body' => $response->body(),
            ]);

            return new CloudApiEnvioResultado(null, $response->status(), $respuesta);
        }

        return new CloudApiEnvioResultado($response->json('messages.0.id'), $response->status(), $respuesta);
    }

    /**
     * Escritor del webhook: procesa el `value` de un change con
     * field === 'phone_number_quality_update'. Parser event-only —
     * confirmado contra la muestra real de Meta App Dashboard (v26.0), que
     * no trae ningún campo de rating, solo `event`.
     *
     * FLAGGED/UNFLAGGED escriben un veredicto nuevo. Cualquier otro valor
     * (ONBOARDING/UPGRADE/DOWNGRADE, o uno ausente/no reconocido) deja el
     * cache intacto: los primeros son transiciones de tier/capacidad, no
     * señales de deliverability, y sobreescribir un veredicto real con eso
     * destruiría información. Devuelve null cuando no escribió nada.
     */
    public function registrarCalidad(array $value): ?array
    {
        $event = $value['event'] ?? null;

        $rating = match ($event) {
            'FLAGGED' => 'RED',
            'UNFLAGGED' => 'GREEN',
            default => null,
        };

        if ($rating === null) {
            $contexto = ['event' => $event, 'value' => $value];

            if (in_array($event, ['ONBOARDING', 'UPGRADE', 'DOWNGRADE'], true)) {
                Log::info('whatsapp.calidad.evento_tier', $contexto);
            } else {
                Log::warning('whatsapp.calidad.evento_no_reconocido', $contexto);
            }

            return null;
        }

        $registro = [
            'quality_rating' => $rating,
            'messaging_limit' => $value['current_limit'] ?? null,
            'event' => $event,
            'origen' => 'webhook',
            'checked_at' => now()->toIso8601String(),
        ];

        Cache::forever(self::CACHE_KEY_SALUD, $registro);

        return $registro;
    }

    /**
     * Path de lectura usado en request time: solo cache, nunca HTTP.
     * Miss (nunca sembrado, o `cache:clear` manual) resuelve a saludable
     * (fail-open) — no bloquear el envío automático por falta de dato.
     */
    public function estaSaludable(): bool
    {
        $cache = Cache::get(self::CACHE_KEY_SALUD);
        $rating = $cache['quality_rating'] ?? null;

        if ($rating === null) {
            return true;
        }

        return ! in_array($rating, config('services.whatsapp_cloud.calidad_bloqueante'), true);
    }
}

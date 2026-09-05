<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudApiService
{
    public const CACHE_KEY_SALUD = 'whatsapp_cloud:salud_numero';

    /**
     * Clave de cache del veredicto de salud, derivada del phone_number_id.
     *
     * Backward-compatible por construcción (§4 del diseño): el número
     * compartido de todo el SaaS — y cualquier caller que no pase argumento —
     * sigue usando la clave legacy CACHE_KEY_SALUD tal cual. Solo un
     * phone_number_id de tenant distinto del compartido obtiene su propio
     * namespace `CACHE_KEY_SALUD:{phone_number_id}`. Así el veredicto real
     * sembrado en prod y los 8 test files que referencian CACHE_KEY_SALUD
     * quedan intactos.
     */
    public static function claveSalud(?string $phoneNumberId = null): string
    {
        $compartido = config('services.whatsapp_cloud.phone_number_id');

        if ($phoneNumberId === null || $phoneNumberId === '' || $phoneNumberId === $compartido) {
            return self::CACHE_KEY_SALUD;
        }

        return self::CACHE_KEY_SALUD.':'.$phoneNumberId;
    }

    private string $token = '';

    private string $phoneNumberId = '';

    private string $apiVersion = '';

    public function __construct()
    {
        $this->token = config('services.whatsapp_cloud.token') ?? '';
        $this->phoneNumberId = config('services.whatsapp_cloud.phone_number_id') ?? '';
        $this->apiVersion = config('services.whatsapp_cloud.api_version') ?? '';
    }

    private function headersCon(string $token): array
    {
        return [
            'Authorization' => "Bearer {$token}",
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
     *
     * $token/$phoneNumberId permiten enviar por el número propio de un
     * tenant (ver User::credencialesWhatsapp) en vez del número compartido
     * del constructor. Ambos son opcionales y `string`, así que los callers
     * DEBEN pasarlos por argumento nombrado (`token:`, `phoneNumberId:`) —
     * una transposición posicional entre dos strings sería silenciosa.
     */
    public function enviarPlantilla(
        string $numero,
        string $template,
        string $idioma,
        array $parametros,
        ?string $token = null,
        ?string $phoneNumberId = null,
    ): CloudApiEnvioResultado {
        $numero = $this->normalizarNumero($numero);
        $tokenEfectivo = $token ?? $this->token;
        $numeroEfectivo = $phoneNumberId ?? $this->phoneNumberId;

        $response = Http::withHeaders($this->headersCon($tokenEfectivo))
            ->post("https://graph.facebook.com/{$this->apiVersion}/{$numeroEfectivo}/messages", [
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
    public function registrarCalidad(array $value, ?string $phoneNumberId = null): ?array
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

        $contexto = [
            'display_phone_number' => $value['display_phone_number'] ?? null,
            'event' => $event,
            'quality_rating' => $rating,
        ];

        if ($rating === 'RED') {
            Log::warning('whatsapp.calidad.veredicto_rojo', $contexto);
        } else {
            Log::info('whatsapp.calidad.veredicto_verde', $contexto);
        }

        Cache::forever(self::claveSalud($phoneNumberId), $registro);

        return $registro;
    }

    /**
     * Sembrado en frío, manual y no programado (`whatsapp:sembrar-salud`):
     * una única llamada GET al mismo phone_number_id que arma un veredicto
     * cuando todavía no llegó ningún webhook de calidad. También sirve
     * para verificar post-deploy que el token tiene el scope de lectura
     * (`whatsapp_business_management`, distinto del de envío).
     *
     * Falla → null, no toca el cache. Fallas de auth (401/403, indicio de
     * scope faltante) se loguean distinto de fallas de transporte
     * genéricas, para diagnosticar rápido cuál de las dos es.
     */
    public function sembrarSalud(): ?array
    {
        try {
            $response = Http::withHeaders($this->headersCon($this->token))
                ->timeout(5)
                ->get("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}", [
                    'fields' => 'quality_rating',
                ]);
        } catch (\Throwable $e) {
            Log::error('CloudApiService::sembrarSalud falló por transporte', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            $contexto = [
                'status' => $response->status(),
                'body' => $response->body(),
            ];

            if (in_array($response->status(), [401, 403], true)) {
                Log::error('CloudApiService::sembrarSalud falló por permisos (revisar scope whatsapp_business_management)', $contexto);
            } else {
                Log::error('CloudApiService::sembrarSalud falló', $contexto);
            }

            return null;
        }

        $registro = [
            'quality_rating' => $response->json('quality_rating'),
            'messaging_limit' => null,
            'event' => null,
            'origen' => 'seed',
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
    public function estaSaludable(?string $phoneNumberId = null): bool
    {
        try {
            $cache = Cache::get(self::claveSalud($phoneNumberId));
        } catch (\Throwable $e) {
            Log::error('CloudApiService::estaSaludable falló al leer el cache, degradando a saludable (fail-open)', [
                'exception' => $e->getMessage(),
            ]);

            return true;
        }

        $rating = $cache['quality_rating'] ?? null;

        if ($rating === null) {
            return true;
        }

        return ! in_array($rating, config('services.whatsapp_cloud.calidad_bloqueante'), true);
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudApiService
{
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
}

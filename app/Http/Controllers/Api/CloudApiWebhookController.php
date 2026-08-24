<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappMensaje;
use App\Services\CloudApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class CloudApiWebhookController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/webhooks/whatsapp-cloud
    // Handshake de verificación que exige Meta al configurar la URL del
    // webhook. Manda hub.mode / hub.verify_token / hub.challenge — PHP
    // convierte los puntos de nombres de query params en guiones bajos al
    // parsear (comportamiento real de parse_str, no un typo), así que acá
    // llegan como hub_mode / hub_verify_token / hub_challenge.
    // ─────────────────────────────────────────────
    public function verify(Request $request): Response
    {
        $modo = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = (string) $request->query('hub_challenge');

        $tokenValido = config('services.whatsapp_cloud.verify_token');

        if ($modo !== 'subscribe' || ! $tokenValido || ! hash_equals($tokenValido, (string) $token)) {
            abort(403);
        }

        return response($challenge, 200);
    }

    // ─────────────────────────────────────────────
    // POST /api/webhooks/whatsapp-cloud
    // Meta firma cada payload con el header X-Hub-Signature-256
    // (sha256=<hmac hex> sobre el body crudo, con el App Secret) — a
    // diferencia de Evolution, acá no hay secreto en la URL.
    // ─────────────────────────────────────────────
    public function handle(Request $request, CloudApiService $cloudApi): JsonResponse
    {
        $appSecret = config('services.whatsapp_cloud.app_secret');
        $header = $request->header('X-Hub-Signature-256', '');

        if (! $appSecret || ! str_starts_with($header, 'sha256=')) {
            abort(404);
        }

        $firmaRecibida = substr($header, strlen('sha256='));
        $firmaEsperada = hash_hmac('sha256', $request->getContent(), $appSecret);

        if (! hash_equals($firmaEsperada, $firmaRecibida)) {
            abort(404);
        }

        $payload = json_decode($request->getContent(), true) ?? [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                // `value.messages[]` (mensajes entrantes) se ignora a propósito:
                // con un solo número Cloud API compartido para todo el SaaS,
                // todavía no está decidido cómo desambiguar a qué profesional/
                // cliente pertenece un mensaje entrante — se resuelve antes de
                // migrar profesionales reales más allá del piloto.
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $this->procesarStatus($status);
                }

                // Rama hermana de la de arriba (no elseif): un mismo payload
                // puede traer ambos campos en el mismo `changes[]` y hay que
                // procesar los dos. try/catch defensivo: un value con forma
                // inesperada nunca debe tumbar la respuesta 200 ni afectar el
                // procesamiento de statuses del resto del payload.
                if (($change['field'] ?? null) === 'phone_number_quality_update') {
                    try {
                        $cloudApi->registrarCalidad($change['value'] ?? []);
                    } catch (\Throwable $e) {
                        Log::warning('WhatsApp Cloud API: no se pudo procesar phone_number_quality_update', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    private function procesarStatus(array $status): void
    {
        $messageId = $status['id'] ?? null;
        $estadoMeta = $status['status'] ?? null;

        if (! $messageId || ! $estadoMeta) {
            return;
        }

        $registro = WhatsappMensaje::where('message_id', $messageId)->first();

        if (! $registro) {
            return;
        } // no es nuestro, ignorar sin loguear

        $nuevoStatus = match ($estadoMeta) {
            'sent' => 'pending',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed',
            default => null,
        };

        if (! $nuevoStatus) {
            return;
        }

        $registro->update(['status' => $nuevoStatus]);

        // Un fallo al escribir el log no debe tumbar la respuesta 200 que ya
        // se va a devolver — el estado ya quedó persistido arriba.
        try {
            $contexto = [
                'message_id' => $messageId,
                'turno_id' => $registro->turno_id,
                'status' => $nuevoStatus,
            ];

            if ($nuevoStatus === 'failed') {
                $contexto['errors'] = $status['errors'] ?? null;
                Log::error('WhatsApp Cloud API: mensaje con error de entrega', $contexto);
            } else {
                Log::info('WhatsApp Cloud API: estado de mensaje actualizado', $contexto);
            }
        } catch (\Throwable $e) {
            // no-op: el estado ya se guardó, el log es best-effort
        }
    }
}

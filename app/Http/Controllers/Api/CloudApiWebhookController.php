<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappConnection;
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
                $field = $change['field'] ?? null;

                // `value.messages[]` (mensajes entrantes) se ignora a propósito:
                // con un solo número Cloud API compartido para todo el SaaS,
                // todavía no está decidido cómo desambiguar a qué profesional/
                // cliente pertenece un mensaje entrante — se resuelve antes de
                // migrar profesionales reales más allá del piloto. El ruteo
                // por-tenant de calidad ya existe (abajo); la pregunta abierta
                // es la de ownership del entrante.
                //
                // procesarStatus() queda FUERA del try/catch de calidad: un
                // error de DB acá debe propagar a 500 para que Meta reintente,
                // nunca convertirse en un 200 + warning que pierde el update.
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $this->procesarStatus($status);
                }

                // Rama hermana de la de arriba (no elseif): un mismo payload
                // puede traer ambos campos en el mismo `changes[]`. try/catch
                // defensivo: SOLO envuelve el resolver + la rama de calidad —
                // un value con forma inesperada nunca debe tumbar la respuesta
                // 200 ni el procesamiento de statuses del resto del payload.
                if ($field === 'phone_number_quality_update') {
                    try {
                        $value = is_array($change['value'] ?? null) ? $change['value'] : [];

                        if (! WhatsappConnection::exists()) {
                            // Rama 1 — modo legacy / número único: escritura
                            // incondicional en la clave compartida, sin gating
                            // ni resolver. Idéntico al comportamiento previo.
                            $cloudApi->registrarCalidad($value);
                        } else {
                            // Rama 2 — hay al menos un número de tenant: se
                            // resuelve perezosamente (solo acá, nunca antes del
                            // loop de statuses) a qué conexión pertenece.
                            $conexion = WhatsappConnection::resolverDesdeWebhook($entry, $change);

                            if ($conexion !== null) {
                                $cloudApi->registrarCalidad($value, $conexion->phone_number_id);
                            } elseif ($this->eventoEsDelNumeroCompartido($value)) {
                                $cloudApi->registrarCalidad($value);
                            } else {
                                Log::warning('whatsapp.calidad.evento_sin_ruta', [
                                    'entry_id' => $entry['id'] ?? null,
                                ]);
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning('whatsapp.webhook.calidad_no_procesada', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                } elseif ($field !== null && $field !== 'messages') {
                    // Seam Q6 (`account_update` y futuros): hoy estos eventos se
                    // tragan en silencio. A partir de acá se ven en logs y
                    // agregar su handler es una rama nueva, no un refactor.
                    // `messages` se excluye a propósito (inundaría el log).
                    Log::info('whatsapp.webhook.field_no_manejado', [
                        'field' => $field,
                        'entry_id' => $entry['id'] ?? null,
                    ]);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * ¿El evento de calidad identifica positivamente al número compartido de
     * Turnetto? Se consulta SOLO en la rama 2 (cuando ya hay conexiones de
     * tenant y el resolver no matcheó): sin un `phone_number_id` compartido
     * configurado no hay forma de afirmarlo, así que devuelve false.
     */
    private function eventoEsDelNumeroCompartido(array $value): bool
    {
        $compartido = config('services.whatsapp_cloud.phone_number_id');

        if (empty($compartido)) {
            return false;
        }

        if (($value['metadata']['phone_number_id'] ?? null) === $compartido) {
            return true;
        }

        $display = $value['display_phone_number'] ?? null;

        return $display !== null
            && preg_replace('/\D/', '', (string) $display) === preg_replace('/\D/', '', (string) $compartido);
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

        // Meta no garantiza el orden de entrega de los webhooks de status
        // (reintentos, colas paralelas) — sin esto, un 'sent' que llega
        // tarde después de un 'read'/'delivered' ya procesado hacía
        // RETROCEDER el status en silencio. Se descarta cualquier evento
        // cronológicamente más viejo (según el reloj de Meta, no el
        // nuestro) que el último ya aplicado — comparación de enteros Unix
        // crudos (ver comentario en la migración add_status_event_at sobre
        // por qué NO es un Carbon/datetime). Si el payload no trae
        // timestamp (no debería pasar en la práctica), se aplica igual —
        // más forgiving que bloquear una actualización real por un dato
        // ausente.
        $eventoTimestamp = isset($status['timestamp']) ? (int) $status['timestamp'] : null;

        if ($eventoTimestamp !== null && $registro->status_event_at !== null && $eventoTimestamp < $registro->status_event_at) {
            Log::info('WhatsApp Cloud API: status descartado por llegar fuera de orden', [
                'message_id' => $messageId,
                'status_actual' => $registro->status,
                'status_descartado' => $nuevoStatus,
                'evento_timestamp' => $eventoTimestamp,
                'ultimo_evento_aplicado_timestamp' => $registro->status_event_at,
            ]);

            return;
        }

        $datos = [
            'status' => $nuevoStatus,
            'status_event_at' => $eventoTimestamp ?? $registro->status_event_at,
        ];

        // Un status 'failed' de Meta trae la razón real de la no-entrega en
        // `errors[]` (normalmente un solo elemento). Se persiste en la fila
        // además de loguearla. Aplica a cualquier `tipo` (confirmacion y
        // recordatorio pasan por acá). Un 'failed' no se recupera en la
        // práctica, así que un 'delivered'/'read' posterior — que no debería
        // llegar — no limpia estos campos a propósito: se deja la evidencia
        // del fallo.
        if ($nuevoStatus === 'failed') {
            $datos += $this->extraerCamposError($status);
        }

        $registro->update($datos);

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

    /**
     * Extrae code / title / detalle del primer elemento de `errors[]` de un
     * status 'failed' de Meta. Null-safe en todos los accesos; el detalle cae
     * a `errors[0].message` cuando no hay `error_data.details`. Trunca a los
     * límites de las columnas (255 / 500) para no reventar el INSERT en
     * Postgres si Meta manda un texto largo.
     *
     * @return array{error_code: int|null, error_titulo: string|null, error_detalle: string|null}
     */
    private function extraerCamposError(array $status): array
    {
        $errores = $status['errors'] ?? null;
        $primero = is_array($errores) ? ($errores[0] ?? null) : null;

        if (! is_array($primero)) {
            return ['error_code' => null, 'error_titulo' => null, 'error_detalle' => null];
        }

        $codigo = $primero['code'] ?? null;
        $titulo = $primero['title'] ?? null;
        $detalle = $primero['error_data']['details'] ?? $primero['message'] ?? null;

        return [
            'error_code' => is_numeric($codigo) ? (int) $codigo : null,
            'error_titulo' => $titulo !== null ? mb_substr((string) $titulo, 0, 255) : null,
            'error_detalle' => $detalle !== null ? mb_substr((string) $detalle, 0, 500) : null,
        ];
    }
}

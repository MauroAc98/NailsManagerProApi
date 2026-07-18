<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\User;
use App\Models\WhatsappEstadoHistorial;
use App\Models\WhatsappMensaje;
use App\Services\EvolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EvolutionWebhookController extends Controller
{
    public function __construct(
        private EvolutionService $evolutionService,
    ) {}

    public function handle(Request $request, string $secret): JsonResponse
    {
        $secretoValido = config('services.evolution.webhook_secret');

        // 404 en vez de 401/403: no delatar que la ruta existe ante un
        // secreto incorrecto. hash_equals evita timing attacks sobre la
        // comparación.
        if (! $secretoValido || ! hash_equals($secretoValido, $secret)) {
            abort(404);
        }

        $event = $request->input('event');
        $instanceName = $request->input('instance');
        $data = $request->input('data');

        // Sin nombre de instancia no hay nada que resolver — mismo trato
        // que un evento desconocido, en vez de un TypeError si el payload
        // viene incompleto.
        if (! is_string($instanceName) || $instanceName === '') {
            return response()->json(['ok' => true]);
        }

        // ── Manejar cambios de estado de conexión ──
        if ($event === 'connection.update' || $event === 'CONNECTION_UPDATE') {
            return $this->handleConnectionUpdate($instanceName, $data);
        }

        // ── Manejar actualizaciones de mensajes ──
        if ($event === 'messages.update' || $event === 'MESSAGES_UPDATE') {
            return $this->handleMessagesUpdate($data);
        }

        // ── Manejar mensajes entrantes (opt-out por "BAJA") ──
        if ($event === 'messages.upsert' || $event === 'MESSAGES_UPSERT') {
            return $this->handleMessagesUpsert($instanceName, $data);
        }

        // Ignorar cualquier otro evento sin loguear
        return response()->json(['ok' => true]);
    }

    private function handleConnectionUpdate(string $instanceName, array $data): JsonResponse
    {
        $user = User::where('evolution_instance_name', $instanceName)->first();

        if (! $user) {
            return response()->json(['ok' => true]);
        }

        $estadoReal = $data['state'] ?? null;
        $statusReason = $data['statusReason'] ?? null;

        // Códigos de Baileys que indican un fallo terminal de la conexión:
        // 401 loggedOut/device_removed, 408 timedOut, 428 connectionClosed,
        // 440 connectionReplaced, 515 restartRequired. Sin esto, cualquier
        // razón distinta de 401 caía en el default y dejaba al usuario
        // "colgado" en conectando sin ninguna señal de que falló.
        $fallosTerminales = [401, 408, 428, 440, 515];

        $estado = match (true) {
            $estadoReal === 'open' => 'conectado',
            $estadoReal === 'close' => 'desconectado',
            in_array($statusReason, $fallosTerminales, true) => 'desconectado',
            default => 'conectando',
        };

        // Registrar en el historial solo cuando el estado realmente cambia,
        // para no llenar la tabla con reintentos/pings que repiten el mismo estado.
        if ($user->whatsapp_estado !== $estado) {
            WhatsappEstadoHistorial::registrar($user, $estado, $statusReason);
        }

        $user->update(['whatsapp_estado' => $estado]);

        // Si la conexión se cae de verdad a mitad de un intento de QR, el
        // guard de logout de generarQr() (ventana deslizante de 45s) puede
        // seguir vigente por los refreshes que ya venían llegando — sin
        // esto, esos refreshes se saltearían la limpieza justo cuando la
        // sesión quedó en el estado sucio que esa limpieza existe para
        // resolver.
        if ($estado === 'desconectado') {
            Cache::forget("evolution_qr_intento_{$user->id}");
        }

        // Un fallo al escribir el log (ej. permisos rotos) no debe tumbar
        // la actualización de estado que ya se persistió arriba.
        try {
            Log::info('Evolution webhook: estado actualizado', [
                'user_id' => $user->id,
                'estado' => $estado,
                'statusReason' => $statusReason,
            ]);
        } catch (\Throwable $e) {
            // no-op: el estado ya se guardó, el log es best-effort
        }

        return response()->json(['ok' => true]);
    }

    private function handleMessagesUpdate(mixed $data): JsonResponse
    {
        // Evolution puede mandar un array de actualizaciones
        $updates = is_array($data) && isset($data[0]) ? $data : [$data];

        foreach ($updates as $update) {
            // Evolution API v2.2.3 manda keyId y status directamente
            // Soporte para ambas estructuras por compatibilidad
            $messageId = $update['keyId'] ?? $update['key']['id'] ?? null;
            $status = $update['status'] ?? $update['update']['status'] ?? null;

            if (! $messageId || ! $status) {
                continue;
            }

            // Buscar si es un mensaje que enviamos nosotros
            $registro = WhatsappMensaje::where('message_id', $messageId)->first();

            if (! $registro) {
                continue;
            } // no es nuestro, ignorar sin loguear

            // Evolution API manda status como string o numérico (Baileys)
            // Numeric: 3=DELIVERY_ACK, 4=READ, 5=PLAYED (implica leído)
            $nuevoStatus = match (true) {
                $status === 'DELIVERY_ACK' || $status === 3 => 'delivered',
                $status === 'READ' || $status === 4 => 'read',
                $status === 'PLAYED' || $status === 5 => 'read',
                default => null,
            };

            if ($nuevoStatus) {
                $registro->update(['status' => $nuevoStatus]);

                try {
                    Log::info('WhatsApp mensaje entregado', [
                        'message_id' => $messageId,
                        'status' => $nuevoStatus,
                    ]);
                } catch (\Throwable $e) {
                    // no-op: el estado ya se guardó, el log es best-effort
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    private const MENSAJE_OPT_OUT = 'BAJA';

    private const MENSAJE_CONFIRMACION_OPT_OUT = 'Listo, no vas a recibir más mensajes automáticos nuestros. Si en algún momento querés reactivarlos, avisale al salón.';

    private function handleMessagesUpsert(string $instanceName, mixed $data): JsonResponse
    {
        // Evolution puede mandar un array de mensajes o uno solo, igual que en messages.update
        $mensajes = is_array($data) && isset($data[0]) ? $data : [$data];

        $user = User::where('evolution_instance_name', $instanceName)->first();

        if (! $user) {
            return response()->json(['ok' => true]);
        }

        foreach ($mensajes as $mensaje) {
            // Ignorar los mensajes que salen de nuestro propio número (confirmaciones,
            // recordatorios, la respuesta de opt-out de este mismo handler, etc.)
            if (($mensaje['key']['fromMe'] ?? true) !== false) {
                continue;
            }

            $remoteJid = $mensaje['key']['remoteJid'] ?? null;
            $texto = $mensaje['message']['conversation']
                ?? $mensaje['message']['extendedTextMessage']['text']
                ?? null;

            // Solo chats 1:1 reales: un grupo (@g.us) o un JID tipo @lid
            // tratado como si fuera un teléfono podría matchear por
            // casualidad contra los últimos 10 dígitos de algún cliente.
            if (! $remoteJid || ! $texto || ! str_ends_with($remoteJid, '@s.whatsapp.net')) {
                continue;
            }

            // Comparación exacta (no "contiene"), para no disparar el opt-out
            // con frases que solo mencionan la palabra de paso.
            if (mb_strtoupper(trim($texto)) !== self::MENSAJE_OPT_OUT) {
                continue;
            }

            $clientes = Cliente::todosPorTelefono($user, $remoteJid);

            if ($clientes->isEmpty()) {
                continue;
            }

            $clientes->each(fn (Cliente $cliente) => $cliente->update(['whatsapp_opt_out' => true]));

            $numeroRemitente = preg_replace('/\D/', '', explode('@', $remoteJid)[0]);
            $this->evolutionService->enviarMensaje($user, $numeroRemitente, self::MENSAJE_CONFIRMACION_OPT_OUT);

            try {
                Log::info('WhatsApp opt-out registrado', [
                    'user_id' => $user->id,
                    'cliente_ids' => $clientes->pluck('id')->all(),
                ]);
            } catch (\Throwable $e) {
                // no-op: el opt-out ya se guardó, el log es best-effort
            }
        }

        return response()->json(['ok' => true]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsappEstadoHistorial;
use App\Models\WhatsappMensaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EvolutionWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $event = $request->input('event');
        $instanceName = $request->input('instance');
        $data = $request->input('data');

        // ── Manejar cambios de estado de conexión ──
        if ($event === 'connection.update' || $event === 'CONNECTION_UPDATE') {
            return $this->handleConnectionUpdate($instanceName, $data);
        }

        // ── Manejar actualizaciones de mensajes ──
        if ($event === 'messages.update' || $event === 'MESSAGES_UPDATE') {
            return $this->handleMessagesUpdate($data);
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
            WhatsappEstadoHistorial::create([
                'user_id' => $user->id,
                'estado' => $estado,
                'status_reason' => $statusReason,
            ]);
        }

        $user->update(['whatsapp_estado' => $estado]);

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
}

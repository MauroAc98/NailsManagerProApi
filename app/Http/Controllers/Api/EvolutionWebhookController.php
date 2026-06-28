<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class EvolutionWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $event        = $request->input('event');
        $instanceName = $request->input('instance');
        $data         = $request->input('data');

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

        if (!$user) {
            return response()->json(['ok' => true]);
        }

        $estadoReal = $data['state'] ?? null;

        $estado = match ($estadoReal) {
            'open'  => 'conectado',
            'close' => 'desconectado',
            default => 'conectando',
        };

        $user->update(['whatsapp_estado' => $estado]);

        Log::info('Evolution webhook: estado actualizado', [
            'user_id' => $user->id,
            'estado'  => $estado,
        ]);

        return response()->json(['ok' => true]);
    }

    private function handleMessagesUpdate(mixed $data): JsonResponse
    {
        // Evolution puede mandar un array de actualizaciones
        $updates = is_array($data) && isset($data[0]) ? $data : [$data];

        foreach ($updates as $update) {
            $messageId = $update['key']['id'] ?? null;
            $status    = $update['update']['status'] ?? null;

            if (!$messageId || !$status) continue;

            // Buscar si es un mensaje que enviamos nosotros
            $registro = WhatsappMensaje::where('message_id', $messageId)->first();

            if (!$registro) continue; // no es nuestro, ignorar sin loguear

            // Mapear status numérico de WhatsApp a texto
            // 1 = PENDING, 2 = SERVER_ACK, 3 = DELIVERY_ACK, 4 = READ
            $nuevoStatus = match ((int) $status) {
                3       => 'delivered',
                4       => 'read',
                default => null,
            };

            if ($nuevoStatus) {
                $registro->update(['status' => $nuevoStatus]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
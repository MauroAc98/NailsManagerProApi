<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class EvolutionWebhookController extends Controller
{
    /**
     * POST /api/webhooks/evolution
     * Recibe eventos de Evolution API. Esta ruta es pública (sin auth:sanctum)
     * porque la llama Evolution API directamente, no un usuario logueado.
     *
     * Por ahora solo procesamos CONNECTION_UPDATE, que nos avisa cuando
     * una instancia pasa a 'open' (conectado) o 'close' (desconectado),
     * incluyendo desconexiones espontáneas (batería, logout manual, etc.)
     * que de otra forma solo se detectarían la próxima vez que la app
     * consulte el estado manualmente.
     */
    public function handle(Request $request): JsonResponse
    {
        $event        = $request->input('event');
        $instanceName = $request->input('instance');
        $data         = $request->input('data');

        Log::info('Evolution webhook recibido', [
            'event'    => $event,
            'instance' => $instanceName,
            'data'     => $data,
        ]);

        if ($event !== 'connection.update' && $event !== 'CONNECTION_UPDATE') {
            return response()->json(['ok' => true]); // ignoramos otros eventos
        }

        $user = User::where('evolution_instance_name', $instanceName)->first();

        if (!$user) {
            Log::warning('Evolution webhook: usuario no encontrado para instancia', [
                'instance' => $instanceName,
            ]);
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
}
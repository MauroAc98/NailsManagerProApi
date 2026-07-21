<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsappEstadoHistorial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    private function noAutorizado(Request $request): ?JsonResponse
    {
        if ($request->header('X-Admin-Secret') !== config('app.admin_secret')) {
            return response()->json(['error' => 'No autorizado'], 401);
        }

        return null;
    }

    /**
     * DIAGNÓSTICO TEMPORAL — no compara nada, solo informa qué recibió
     * el servidor vs. lo que espera, sin exponer el secret en texto plano.
     * Sacar este método y su ruta una vez resuelto el mismatch de Postman.
     */
    public function debugAdminSecret(Request $request): JsonResponse
    {
        $received = $request->header('X-Admin-Secret') ?? '';
        $expected = config('app.admin_secret') ?? '';

        return response()->json([
            'received_length' => strlen($received),
            'received_sha256' => hash('sha256', $received),
            'expected_length' => strlen($expected),
            'expected_sha256' => hash('sha256', $expected),
            'matches' => hash_equals($expected, $received),
        ]);
    }

    public function renewSubscription(Request $request, User $user): JsonResponse
    {
        if ($response = $this->noAutorizado($request)) {
            return $response;
        }

        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json(['error' => 'El usuario no tiene suscripción'], 404);
        }

        $subscription->update([
            'ends_at' => now()->max($subscription->ends_at)->copy()->addDays(30),
            'status'  => 'ACTIVO',
        ]);

        return response()->json([
            'message' => 'Suscripción renovada correctamente',
            'user_id' => $user->id,
            'ends_at' => $subscription->fresh()->ends_at,
        ]);
    }

    /**
     * GET /api/admin/whatsapp/instancias
     * Lista todas las instancias de Evolution (una por usuario) con su
     * estado actual y la fecha del último cambio de estado registrado.
     */
    public function whatsappInstancias(Request $request): JsonResponse
    {
        if ($response = $this->noAutorizado($request)) {
            return $response;
        }

        $usuarios = User::whereNotNull('evolution_instance_name')
            ->withMax('whatsappEstadoHistoriales as ultimo_cambio_at', 'created_at')
            ->get(['id', 'name', 'evolution_instance_name', 'whatsapp_estado']);

        return response()->json([
            'instancias' => $usuarios->map(fn (User $user) => [
                'user_id' => $user->id,
                'nombre' => $user->name,
                'instance_name' => $user->evolution_instance_name,
                'estado' => $user->whatsapp_estado,
                'ultimo_cambio_at' => $user->ultimo_cambio_at,
            ]),
        ]);
    }

    /**
     * GET /api/admin/whatsapp/instancias/{user}/historial
     * Historial de cambios de estado (conexión/desconexión) de la
     * instancia de Evolution de un usuario puntual.
     */
    public function whatsappHistorial(Request $request, User $user): JsonResponse
    {
        if ($response = $this->noAutorizado($request)) {
            return $response;
        }

        $historial = $user->whatsappEstadoHistoriales()
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($historial);
    }
}
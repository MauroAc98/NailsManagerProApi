<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsappEstadoHistorial;
use App\Models\WhatsappMensaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminController extends Controller
{
    // Falla cerrado si ADMIN_SECRET no está seteado en el entorno — ver
    // mismo comentario en AuthController::noAutorizado().
    private function noAutorizado(Request $request): ?JsonResponse
    {
        $secreto = config('app.admin_secret');
        if ($secreto === null || $secreto === '' || $request->header('X-Admin-Secret') !== $secreto) {
            return response()->json(['error' => 'No autorizado'], 401);
        }

        return null;
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

        $renewedRecently = $subscription->renewed_at && $subscription->renewed_at->gt(now()->subHours(24));

        if ($renewedRecently && !$request->boolean('force')) {
            return response()->json([
                'error' => 'Esta suscripción ya fue renovada hace menos de 24hs',
                'renewed_at' => $subscription->renewed_at,
                'ends_at' => $subscription->ends_at,
                'hint' => 'Si es intencional, repetí el request con ?force=true',
            ], 409);
        }

        $subscription->update([
            'ends_at' => now()->max($subscription->ends_at)->copy()->addDays(30),
            'status'  => 'ACTIVO',
            'renewed_at' => now(),
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

    /**
     * GET /api/admin/whatsapp/uso-por-salon?desde=&hasta=
     * Uso de WhatsApp Cloud API por salón (Evolution es gratis, no se
     * cuenta) — mensajes crudos y una aproximación de conversaciones de
     * 24hs (Meta cobra por conversación, no por mensaje individual), para
     * poder cotejar cuánto le está costando cada profesional.
     */
    public function usoWhatsappPorSalon(Request $request): JsonResponse
    {
        if ($response = $this->noAutorizado($request)) {
            return $response;
        }

        $desde = $request->filled('desde')
            ? Carbon::parse($request->query('desde'))->startOfDay()
            : now()->startOfMonth();
        $hasta = $request->filled('hasta')
            ? Carbon::parse($request->query('hasta'))->endOfDay()
            : now()->endOfDay();

        $mensajes = WhatsappMensaje::where('provider', 'cloud_api')
            ->whereBetween('created_at', [$desde, $hasta])
            ->orderBy('created_at')
            ->get(['user_id', 'numero', 'created_at']);

        $porUsuario = $mensajes->groupBy('user_id');

        $nombres = User::whereIn('id', $porUsuario->keys())->pluck('name', 'id');

        $salones = $porUsuario->map(function ($mensajesDelUsuario, $userId) use ($nombres) {
            $conversaciones = 0;

            foreach ($mensajesDelUsuario->groupBy('numero') as $mensajesDelNumero) {
                $inicioVentana = null;

                foreach ($mensajesDelNumero as $mensaje) {
                    // diffInHours() en Carbon 3 devuelve el valor CON SIGNO por
                    // default (a diferencia de Carbon 2) — sin absolute:true acá
                    // daba negativo y la comparación ">= 24" nunca abría una
                    // conversación nueva.
                    if ($inicioVentana === null || $mensaje->created_at->diffInHours($inicioVentana, absolute: true) >= 24) {
                        $conversaciones++;
                        $inicioVentana = $mensaje->created_at;
                    }
                }
            }

            return [
                'user_id' => (int) $userId,
                'nombre' => $nombres[$userId] ?? null,
                'mensajes_totales' => $mensajesDelUsuario->count(),
                'conversaciones_estimadas' => $conversaciones,
            ];
        })
            ->sortByDesc('conversaciones_estimadas')
            ->values();

        return response()->json([
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'salones' => $salones,
        ]);
    }
}
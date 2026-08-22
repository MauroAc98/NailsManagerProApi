<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Services\AdminAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminController extends Controller
{
    // La autorización de admin/* la resuelve el middleware auth:admin
    // (routes/api.php) — ver AdminUser / config/auth.php.
    public function renewSubscription(Request $request, User $user): JsonResponse
    {
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

        AdminAudit::record($request->user('admin'), 'suscripcion.renovada', $user->id, [
            'ends_at' => $subscription->fresh()->ends_at,
        ], $request);

        return response()->json([
            'message' => 'Suscripción renovada correctamente',
            'user_id' => $user->id,
            'ends_at' => $subscription->fresh()->ends_at,
        ]);
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

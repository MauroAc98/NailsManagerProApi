<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // El gate es el mismo criterio que User::suscripcionVencida() (que
        // usan además los envíos pagos de WhatsApp), pero acá se desglosa la
        // causa en un `code` distinto para que el frontend pueda mostrar el
        // motivo real. El string `error` se mantiene por compatibilidad.
        // Orden: causa más específica primero — sin-fila, luego SUSPENDIDO
        // (tiene precedencia sobre la expiración: una cuenta suspendida con
        // días por delante igual queda cortada), luego ends_at pasado.
        if ($user->is_exempt) {
            return $next($request);
        }

        $subscription = $user->subscription;

        if (! $subscription) {
            return response()->json([
                'error' => 'Suscripción vencida',
                'code'  => 'NO_SUBSCRIPTION',
            ], 403);
        }

        if ($subscription->status === 'SUSPENDIDO') {
            return response()->json([
                'error' => 'Suscripción suspendida',
                'code'  => 'SUBSCRIPTION_SUSPENDED',
            ], 403);
        }

        if ($subscription->ends_at < now()) {
            return response()->json([
                'error' => 'Suscripción vencida',
                'code'  => 'SUBSCRIPTION_EXPIRED',
            ], 403);
        }

        return $next($request);
    }
}
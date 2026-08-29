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

        // Un solo predicado gatea el acceso del tenant, los envíos pagos de
        // WhatsApp y este middleware — cubre is_exempt, sin-suscripción,
        // ends_at pasado y SUSPENDIDO. Ver User::suscripcionVencida().
        if ($user->suscripcionVencida()) {
            return response()->json([
                'error' => 'Suscripción vencida',
                'code'  => 'SUBSCRIPTION_EXPIRED'
            ], 403);
        }

        return $next($request);
    }
}
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

        if ($user->is_exempt) {
            return $next($request);
        }

        $subscription = $user->subscription;

        if (!$subscription || $subscription->ends_at < now()) {
            return response()->json([
                'error' => 'Suscripción vencida',
                'code'  => 'SUBSCRIPTION_EXPIRED'
            ], 403);
        }

        return $next($request);
    }
}
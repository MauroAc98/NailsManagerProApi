<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user->activo) {
            return response()->json([
                'message' => 'Tu cuenta está suspendida. Contactá al administrador.',
            ], 403);
        }

        return $next($request);
    }
}
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un token de admin emitido antes de desactivar la cuenta no puede seguir
 * sirviendo: si el admin autenticado esta inactivo, se revocan sus tokens y se
 * responde 401 (mismo status que un token invalido, sin revelar el motivo).
 * Va despues de auth:admin.
 */
class RequiereAdminActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin && ! $admin->activo) {
            $admin->tokens()->delete();

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}

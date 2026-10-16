<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kill switch of the professionals portal. Must be the FIRST middleware of
 * every portal route so that, while the portal is disabled, nothing about it
 * is observable: not even an unauthenticated caller gets a 401 instead of a 404.
 */
class PortalProfesionalesHabilitado
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('portal_profesionales.habilitado'), 404);

        $response = $next($request);

        // Sessions, tokens and agenda data must never sit in a shared or
        // browser cache. Outermost middleware of the group, so it also covers
        // the 401/403/429 answers produced further in.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

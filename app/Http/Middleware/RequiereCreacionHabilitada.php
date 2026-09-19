<?php

namespace App\Http\Middleware;

use App\Exceptions\ReservaPublicaException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kill switch (config reservas.creacion_habilitada / RESERVAS_CREACION_HABILITADA)
 * de los endpoints de escritura de la reserva online: apagado => 503 creation_disabled.
 */
class RequiereCreacionHabilitada
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('reservas.creacion_habilitada')) {
            return ReservaPublicaException::creationDisabled()->render();
        }

        return $next($request);
    }
}

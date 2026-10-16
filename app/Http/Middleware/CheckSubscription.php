<?php

namespace App\Http\Middleware;

use App\Support\RespuestaSuscripcion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        // El criterio vive en User::codigoDeBloqueoPorSuscripcion() (el mismo
        // que usa suscripcionVencida(), y con ella los envíos pagos de
        // WhatsApp); acá solo se traduce a la respuesta 403, con un `code`
        // distinto por causa para que el frontend muestre el motivo real.
        $code = $request->user()->codigoDeBloqueoPorSuscripcion();

        if ($code !== null) {
            return RespuestaSuscripcion::json($code);
        }

        return $next($request);
    }
}

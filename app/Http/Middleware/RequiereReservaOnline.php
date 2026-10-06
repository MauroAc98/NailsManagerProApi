<?php

namespace App\Http\Middleware;

use App\Exceptions\ReservaPublicaException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Add-on de reserva online: el negocio del slug debe tenerlo contratado y con
 * suscripcion vigente. 404 (no 403) para no revelar que el negocio existe.
 * Si el slug no existe, deja pasar: el controller ya responde su propio 404.
 */
class RequiereReservaOnline
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = User::where('slug', $request->route('slug'))->first();

        if ($user && ! $user->reserva_online_activa) {
            return ReservaPublicaException::notFound()->render();
        }

        return $next($request);
    }
}

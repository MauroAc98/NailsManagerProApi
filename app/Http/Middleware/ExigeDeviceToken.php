<?php

namespace App\Http\Middleware;

use App\Exceptions\ReservaPublicaException;
use App\Services\Reservas\ReputacionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige X-Device-Token: opaco, generado por el cliente ([A-Za-z0-9_-], 32-128).
 * El servidor solo guarda su hmac; deja el hash en el atributo `device_hash`.
 */
class ExigeDeviceToken
{
    public function __construct(private ReputacionService $reputacion)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->header('X-Device-Token', '');

        if (! preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token)) {
            return ReservaPublicaException::deviceTokenRequired()->render();
        }

        $request->attributes->set('device_hash', $this->reputacion->hashDevice($token));

        return $next($request);
    }
}

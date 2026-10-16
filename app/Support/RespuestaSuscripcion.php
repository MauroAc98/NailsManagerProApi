<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Cuerpo 403 de un corte por suscripcion, compartido por el gate tenant
 * (CheckSubscription) y por el portal de profesionales (pro.vigente) para que
 * ambos respondan exactamente igual. El string `error` se mantiene por
 * compatibilidad; el frontend decide por `code`.
 */
class RespuestaSuscripcion
{
    private const MENSAJES = [
        'NO_SUBSCRIPTION'        => 'Suscripción vencida',
        'SUBSCRIPTION_SUSPENDED' => 'Suscripción suspendida',
        'SUBSCRIPTION_EXPIRED'   => 'Suscripción vencida',
    ];

    public static function json(string $code): JsonResponse
    {
        return response()->json([
            'error' => self::MENSAJES[$code],
            'code'  => $code,
        ], 403);
    }
}

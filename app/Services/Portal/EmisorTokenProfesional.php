<?php

namespace App\Services\Portal;

use App\Models\ProfesionalCuenta;
use Illuminate\Support\Carbon;

/**
 * Issues the Sanctum tokens of the professional portal. The token belongs to the
 * ACCOUNT (not to a link); the active negocio is chosen per request.
 *
 * Abilities are never `*`: a full token cannot call the forced password change
 * endpoint and the restricted token cannot call anything else.
 */
class EmisorTokenProfesional
{
    public const DIAS_VIGENCIA = 30;
    public const MINUTOS_RESTRINGIDO = 15;
    public const NOMBRE_RESTRINGIDO = 'pro-cambiar-password';

    /**
     * Full access token. Fixed expiry (no sliding): using it never extends it.
     * Other tokens of the account are never deleted here.
     *
     * @return array{token: string, expires_at: Carbon}
     */
    public function completo(ProfesionalCuenta $cuenta): array
    {
        $expiresAt = now()->addDays(self::DIAS_VIGENCIA);
        $token = $cuenta->createToken('pro-portal', ['pro:acceso'], $expiresAt)->plainTextToken;

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Restricted token for accounts that must change their provisional
     * password: valid only for `cambiar-password`, 15 minutes. At most one
     * exists per account (older ones are deleted); full tokens are untouched.
     *
     * @return array{token: string, expires_at: Carbon}
     */
    public function restringido(ProfesionalCuenta $cuenta): array
    {
        $cuenta->tokens()->where('name', self::NOMBRE_RESTRINGIDO)->delete();

        $expiresAt = now()->addMinutes(self::MINUTOS_RESTRINGIDO);
        $token = $cuenta->createToken(self::NOMBRE_RESTRINGIDO, ['pro:cambiar-password'], $expiresAt)->plainTextToken;

        return ['token' => $token, 'expires_at' => $expiresAt];
    }
}

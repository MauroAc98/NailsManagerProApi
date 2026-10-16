<?php

namespace App\Services\Portal;

use App\Models\ProfesionalCuenta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    public const MAX_DISPOSITIVOS = 5;
    public const NOMBRE_RESTRINGIDO = 'pro-cambiar-password';

    /**
     * Full access token. Fixed expiry (no sliding): using it never extends it.
     *
     * Device cap: an account keeps at most MAX_DISPOSITIVOS live full tokens, so
     * when issuing one would exceed it the least recently used ones are
     * revoked (never the restricted token, never expired ones: they do not
     * count). The cuenta row is locked for the duration so two concurrent
     * logins cannot both read the same count and overshoot the cap.
     *
     * @return array{token: string, expires_at: Carbon}
     */
    public function completo(ProfesionalCuenta $cuenta): array
    {
        $expiresAt = now()->addDays(self::DIAS_VIGENCIA);

        $token = DB::transaction(function () use ($cuenta, $expiresAt) {
            ProfesionalCuenta::whereKey($cuenta->id)->lockForUpdate()->first();

            $this->liberarCupo($cuenta);

            return $cuenta->createToken('pro-portal', ['pro:acceso'], $expiresAt)->plainTextToken;
        });

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    // Revokes the least recently used live full tokens until there is room for
    // one more. Last activity = last_used_at, or created_at if never used. The
    // slice is done in PHP over the ordered ids (no OFFSET).
    private function liberarCupo(ProfesionalCuenta $cuenta): void
    {
        $ids = $cuenta->tokens()
            ->where('name', '!=', self::NOMBRE_RESTRINGIDO)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw('COALESCE(last_used_at, created_at) asc')
            ->orderBy('id')
            ->pluck('id');

        $sobran = $ids->count() - (self::MAX_DISPOSITIVOS - 1);

        if ($sobran > 0) {
            $cuenta->tokens()->whereIn('id', $ids->take($sobran))->delete();
        }
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

<?php

namespace App\Http\Middleware;

use App\Models\ProfesionalVinculo;
use App\Support\RespuestaSuscripcion;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request validity of a professional portal session. Nothing here is
 * cached: every request re-reads the account, the link, the professional and
 * the subscription, so switching any of them off cuts access on the very next
 * request. It goes after auth:profesional and the ability check.
 *
 * `pro.vigente:cuenta` only checks the account (me, logout, cambiar-password).
 * `pro.vigente` (full) is for link-scoped routes and checks, IN THIS ORDER:
 *   1. account active            -> tokens revoked + generic 401
 *   2. no pending password change-> 403 PASSWORD_CAMBIO_REQUERIDO
 *   3. X-Vinculo-Id well formed  -> 422 VINCULO_REQUERIDO
 *   4. link belongs to the account, is active, and its professional is active
 *                                -> 403 VINCULO_NO_DISPONIBLE (one body for
 *                                   every cause, so an id reveals nothing)
 *   5. the link's negocio subscription -> 403 with the tenant codes
 * Ownership (4) runs before the subscription (5) so another account's link
 * can never disclose the state of a negocio. The professional and negocio are
 * derived from the link only and published as the `pro.vinculo` attribute;
 * controllers never read the header or any request input for them.
 */
class RequiereProfesionalVigente
{
    public function handle(Request $request, Closure $next, string $modo = 'completo'): Response
    {
        $cuenta = $request->user('profesional');

        // Same answer as an invalid token: nothing says why. Deleting the
        // tokens makes the cut-off permanent, not just for this request.
        if (! $cuenta || ! $cuenta->activo) {
            $cuenta?->tokens()->delete();

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($modo === 'cuenta') {
            return $next($request);
        }

        if ($cuenta->debe_cambiar_password) {
            return response()->json([
                'message' => 'Tenés que cambiar tu contraseña antes de continuar.',
                'code' => 'PASSWORD_CAMBIO_REQUERIDO',
            ], 403);
        }

        $id = $request->header('X-Vinculo-Id');

        // Positive integer without leading zeros: anything else never reaches a query.
        if (! is_string($id) || ! preg_match('/^[1-9]\d{0,17}\z/', $id)) {
            return response()->json([
                'message' => 'Indicá a qué negocio querés acceder.',
                'code' => 'VINCULO_REQUERIDO',
            ], 422);
        }

        $vinculo = ProfesionalVinculo::query()
            ->where('id', (int) $id)
            ->where('cuenta_id', $cuenta->id)
            ->where('activo', true)
            ->with('profesional.user.subscription')
            ->first();

        if (! $vinculo || ! $vinculo->profesional?->activo) {
            return $this->vinculoNoDisponible();
        }

        $codigo = $vinculo->profesional->user->codigoDeBloqueoPorSuscripcion();

        if ($codigo !== null) {
            return RespuestaSuscripcion::json($codigo);
        }

        $request->attributes->set('pro.vinculo', $vinculo);

        return $next($request);
    }

    private function vinculoNoDisponible(): JsonResponse
    {
        return response()->json([
            'message' => 'Este acceso no está disponible.',
            'code' => 'VINCULO_NO_DISPONIBLE',
        ], 403);
    }
}

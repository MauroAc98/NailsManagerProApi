<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profesional;
use App\Models\User;
use App\Services\Portal\InvitacionRechazada;
use App\Services\Portal\InvitarProfesional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner side of the professionals' portal: the persona titular manages the
 * portal access of HER professionals. Tenant routes (auth:sanctum +
 * subscription.check) behind the `portal.pro` kill switch.
 */
class ProfesionalAccesoController extends Controller
{
    // One fixed body for every successful invitation, whether the email was
    // new, already had an account, or was already linked: it must not reveal
    // which one happened.
    private const MENSAJE_INVITACION = 'Invitación enviada. La persona invitada va a recibir un email con los pasos para ingresar.';

    // ─────────────────────────────────────────────
    // POST /api/profesionales/{id}/acceso
    // ─────────────────────────────────────────────
    public function invitar(Request $request, InvitarProfesional $invitar, string $id): JsonResponse
    {
        $profesional = $this->profesionalDelNegocio($request->user(), $id);
        if (! $profesional) {
            return $this->noEncontrada();
        }

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ], [
            'email.required' => 'Indica el email de la persona a invitar.',
            'email.string' => 'Ingresa un email válido.',
            'email.email' => 'Ingresa un email válido.',
            'email.max' => 'El email es demasiado largo.',
        ]);

        try {
            $invitar->invitar($request->user(), $profesional, $data['email']);
        } catch (InvitacionRechazada $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->codigo], $e->status);
        }

        return response()->json(['message' => self::MENSAJE_INVITACION]);
    }

    /**
     * A professional of THIS negocio, or null. The id comes straight from the
     * route: anything that is not a plain positive integer, a nonexistent id
     * and another negocio's professional all end in the same null, so the 404
     * cannot be used to probe other negocios.
     */
    private function profesionalDelNegocio(User $titular, string $id): ?Profesional
    {
        if (! preg_match('/\A[1-9]\d{0,17}\z/', $id)) {
            return null;
        }

        return Profesional::delUsuario($titular)->find((int) $id);
    }

    private function noEncontrada(): JsonResponse
    {
        return response()->json([
            'message' => 'No encontramos a esa profesional.',
            'code' => 'PROFESIONAL_NO_ENCONTRADA',
        ], 404);
    }
}

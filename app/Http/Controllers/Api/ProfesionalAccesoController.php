<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profesional;
use App\Models\ProfesionalVinculo;
use App\Models\User;
use App\Services\Portal\InvitacionRechazada;
use App\Services\Portal\InvitarProfesional;
use App\Services\Portal\ResetearPasswordProvisoria;
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

    private const MENSAJE_RESET = 'Listo. La persona va a recibir un email con una contraseña provisoria nueva.';

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
            'email.required' => 'Indicá el email de la persona a invitar.',
            'email.string' => 'Ingresá un email válido.',
            'email.email' => 'Ingresá un email válido.',
            'email.max' => 'El email es demasiado largo.',
        ]);

        try {
            $invitar->invitar($request->user(), $profesional, $data['email']);
        } catch (InvitacionRechazada $e) {
            return $this->rechazo($e);
        }

        return response()->json(['message' => self::MENSAJE_INVITACION]);
    }

    // ─────────────────────────────────────────────
    // GET /api/profesionales/accesos
    // The access state of every professional of the titular, in one call.
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $profesionales = Profesional::delUsuario($request->user())
            ->with('vinculo.cuenta:id,email,debe_cambiar_password')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $profesionales
                ->map(fn (Profesional $p) => ['profesional_id' => $p->id] + $this->estadoDeAcceso($p->vinculo))
                ->all(),
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/profesionales/{id}/acceso
    // ─────────────────────────────────────────────
    public function show(Request $request, string $id): JsonResponse
    {
        $profesional = $this->profesionalDelNegocio($request->user(), $id);
        if (! $profesional) {
            return $this->noEncontrada();
        }

        return response()->json($this->estadoDeAcceso($profesional->vinculo()->with('cuenta:id,email,debe_cambiar_password')->first()));
    }

    // ─────────────────────────────────────────────
    // PATCH /api/profesionales/{id}/acceso   { activo }
    // Switches THIS professional's link on or off. Never the account (its
    // `activo`, password, flag or tokens: it may be shared with other negocios)
    // and never another link. The professional portal re-reads the link on
    // every request, so the cut-off is immediate.
    // ─────────────────────────────────────────────
    public function actualizar(Request $request, string $id): JsonResponse
    {
        $profesional = $this->profesionalDelNegocio($request->user(), $id);
        if (! $profesional) {
            return $this->noEncontrada();
        }

        $request->validate([
            'activo' => ['required', 'boolean'],
        ], [
            'activo.required' => 'Indicá si el acceso queda activo o no.',
            'activo.boolean' => 'Indicá si el acceso queda activo o no.',
        ]);
        $activo = $request->boolean('activo');

        $vinculo = $profesional->vinculo;
        if (! $vinculo) {
            return $this->sinAcceso();
        }

        // The titular's own profile can never hold an active link (only
        // reachable by forcing the data; switching it off or removing it is fine).
        if ($activo && $profesional->esPerfilTitular()) {
            return $this->rechazo(InvitacionRechazada::perfilDeLaPersonaTitular());
        }

        $vinculo->update(['activo' => $activo]);

        return response()->json([
            'message' => $activo ? 'Acceso activado.' : 'Acceso desactivado.',
            'activo' => $activo,
        ]);
    }

    // ─────────────────────────────────────────────
    // DELETE /api/profesionales/{id}/acceso
    // Deletes THIS professional's link and nothing else: the account stays (it
    // may belong to other negocios), as do its tokens. The professional loses
    // this negocio on the very next request; a new invitation works afterwards.
    // ─────────────────────────────────────────────
    public function destroy(Request $request, string $id): JsonResponse
    {
        $profesional = $this->profesionalDelNegocio($request->user(), $id);
        if (! $profesional) {
            return $this->noEncontrada();
        }

        $vinculo = $profesional->vinculo;
        if (! $vinculo) {
            return $this->sinAcceso();
        }

        $vinculo->delete();

        return response()->json(['message' => 'Acceso quitado.']);
    }

    // ─────────────────────────────────────────────
    // POST /api/profesionales/{id}/acceso/reset
    // Acts only on an account this invitation created, still pending and not
    // shared; in every other case it does nothing and answers exactly the same,
    // so the reply says nothing about the account. The new provisional password
    // travels by email only.
    // ─────────────────────────────────────────────
    public function resetPassword(Request $request, ResetearPasswordProvisoria $resetear, string $id): JsonResponse
    {
        $profesional = $this->profesionalDelNegocio($request->user(), $id);
        if (! $profesional) {
            return $this->noEncontrada();
        }

        $vinculo = $profesional->vinculo;
        if (! $vinculo) {
            return $this->sinAcceso();
        }

        try {
            $resetear->resetear($request->user(), $profesional, $vinculo);
        } catch (InvitacionRechazada $e) {
            return $this->rechazo($e);
        }

        return response()->json(['message' => self::MENSAJE_RESET]);
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

    /**
     * What the titular may know about one professional's access: the account's
     * email (she typed it), THIS link's state and whether the first login is
     * still pending. The first login is only reported as done for an account
     * THIS invitation created: for any account that already existed the flag
     * stays pending, so it cannot tell her that an email had an account.
     * Deliberately nothing else: no last login, IP, account id, account
     * `activo`, tokens or other links.
     *
     * @return array{tiene_acceso: bool, email: ?string, activo: ?bool, pendiente_de_activacion: ?bool}
     */
    private function estadoDeAcceso(?ProfesionalVinculo $vinculo): array
    {
        return [
            'tiene_acceso' => $vinculo !== null,
            'email' => $vinculo?->cuenta->email,
            'activo' => $vinculo?->activo,
            'pendiente_de_activacion' => $vinculo === null ? null : ! $vinculo->primerIngresoCompletado(),
        ];
    }

    // Her own professional, but with no portal link: it depends only on the
    // titular's own data, so unlike noEncontrada() it can say so.
    private function sinAcceso(): JsonResponse
    {
        return response()->json([
            'message' => 'Esa profesional no tiene acceso al portal.',
            'code' => 'PROFESIONAL_SIN_ACCESO',
        ], 404);
    }

    private function rechazo(InvitacionRechazada $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'code' => $e->codigo], $e->status);
    }
}

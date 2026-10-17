<?php

namespace App\Services\Portal;

use App\Mail\ProfesionalInvitacionMail;
use App\Mail\ProfesionalVinculadaMail;
use App\Models\Profesional;
use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
use App\Models\User;
use App\Support\PasswordProvisoria;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The persona titular invites one of HER professionals to the portal.
 *
 * Rules enforced here (none of them depends on whether the email exists):
 * the titular's own profile and her own email are rejected; an inactive
 * professional is rejected; a professional that already has a link only
 * accepts the SAME email again (idempotent), any other email is a 409.
 *
 * An unknown email creates a provisional account; a known email is linked
 * DIRECTLY and its password, flag and tokens are never touched (decision
 * #1044). The password is generated and hashed before looking at the email, so
 * both paths pay the same hashing cost.
 */
class InvitarProfesional
{
    /**
     * @throws InvitacionRechazada
     */
    public function invitar(User $titular, Profesional $profesional, string $email): void
    {
        $email = strtolower(trim($email));

        if ($profesional->esPerfilTitular()) {
            throw InvitacionRechazada::perfilDeLaPersonaTitular();
        }
        if ($email === strtolower(trim((string) $titular->email))) {
            throw InvitacionRechazada::emailDeLaPersonaTitular();
        }
        if (! $profesional->activo) {
            throw InvitacionRechazada::profesionalInactiva();
        }

        // Always generated and hashed, whether the email turns out to be new
        // or not: the cost must not tell the two cases apart.
        $passwordProvisoria = PasswordProvisoria::generar();
        $hash = Hash::make($passwordProvisoria);

        $vinculoActual = $profesional->vinculo()->with('cuenta:id,email')->first();
        if ($vinculoActual) {
            if ($vinculoActual->cuenta->email === $email) {
                return;
            }

            throw InvitacionRechazada::profesionalConAcceso();
        }

        [$cuenta, $esNueva] = DB::transaction(function () use ($titular, $profesional, $email, $hash) {
            [$cuenta, $esNueva] = $this->cuentaParaEmail($email, $hash);

            try {
                ProfesionalVinculo::create([
                    'cuenta_id' => $cuenta->id,
                    'profesional_id' => $profesional->id,
                    'activo' => true,
                    'invitado_por_user_id' => $titular->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another invitation linked this professional between our
                // check and our insert. Throwing rolls back the account we
                // may have just created.
                throw InvitacionRechazada::profesionalConAcceso();
            }

            return [$cuenta, $esNueva];
        });

        // After the commit: if the SMTP server fails the invitation already
        // exists and must not be rolled back or answered with a 500 (same
        // approach as AdminController::crearNegocio).
        $this->enviarCorreo($titular, $profesional, $cuenta, $esNueva, $passwordProvisoria);
    }

    /**
     * The existing account for the email, untouched, or a new provisional one.
     * If a concurrent request creates it first, the unique index rejects ours
     * (inside its own savepoint, so the outer transaction stays usable) and we
     * link the account that won.
     *
     * @return array{0: ProfesionalCuenta, 1: bool} the account and whether this call created it
     */
    private function cuentaParaEmail(string $email, string $hash): array
    {
        $existente = ProfesionalCuenta::where('email', $email)->first();
        if ($existente) {
            return [$existente, false];
        }

        try {
            $nueva = DB::transaction(fn () => ProfesionalCuenta::create([
                'email' => $email,
                'password' => $hash,
                'activo' => true,
                'debe_cambiar_password' => true,
            ]));

            return [$nueva, true];
        } catch (UniqueConstraintViolationException) {
            return [ProfesionalCuenta::where('email', $email)->firstOrFail(), false];
        }
    }

    /**
     * A brand-new account gets its provisional password; an existing one only
     * hears that it was linked, with no password at all. Failures are logged
     * WITHOUT the password and without the address (an SMTP error message may
     * echo it): only the exception class and a hash of the email.
     */
    private function enviarCorreo(User $titular, Profesional $profesional, ProfesionalCuenta $cuenta, bool $esNueva, string $passwordProvisoria): void
    {
        $url = (string) config('portal_profesionales.url');

        try {
            Mail::to($cuenta->email)->send($esNueva
                ? new ProfesionalInvitacionMail($titular->name, $profesional->nombre, $cuenta->email, $passwordProvisoria, $url)
                : new ProfesionalVinculadaMail($titular->name, $profesional->nombre, $url));
        } catch (\Throwable $e) {
            Log::error('No se pudo enviar el mail de acceso al portal de profesionales', [
                'user_id' => $titular->id,
                'profesional_id' => $profesional->id,
                'cuenta_nueva' => $esNueva,
                'email_hash' => sha1($cuenta->email),
                'excepcion' => $e::class,
            ]);
        }
    }
}

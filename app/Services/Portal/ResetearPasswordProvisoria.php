<?php

namespace App\Services\Portal;

use App\Mail\ProfesionalInvitacionMail;
use App\Models\Profesional;
use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
use App\Models\User;
use App\Support\PasswordProvisoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The persona titular leaves a NEW provisional password for a professional who
 * has not logged in yet (the mail with the first one got lost or discarded).
 *
 * It acts ONLY on an account that her own invitation created, that nobody has
 * logged into yet, and that no other professional is linked to. Everything is
 * checked again under a row lock, so a first login, a second link or a link
 * delete that lands in between wins. In every other case it does NOTHING (no
 * password change, no token deletion, no mail) and the caller answers exactly
 * as it does after a real reset: the titular cannot tell whether an email
 * already had an account, whether it was activated or whether another negocio
 * uses it. (An account holder who lost her password recovers it herself.)
 *
 * The password goes by email only: never in the response, never in a log. It is
 * generated and hashed before any branching, so every path costs the same.
 */
class ResetearPasswordProvisoria
{
    /**
     * @throws InvitacionRechazada the titular's own profile can never be reset
     */
    public function resetear(User $titular, Profesional $profesional, ProfesionalVinculo $vinculo): void
    {
        if ($profesional->esPerfilTitular()) {
            throw InvitacionRechazada::perfilDeLaPersonaTitular();
        }

        $passwordProvisoria = PasswordProvisoria::generar();
        $hash = Hash::make($passwordProvisoria);

        $cuenta = DB::transaction(function () use ($vinculo, $hash) {
            $cuenta = ProfesionalCuenta::whereKey($vinculo->cuenta_id)->lockForUpdate()->first();
            // The link is read again, locked, so a concurrent delete cannot slip
            // past us, and its marker is the stored one, not the one read earlier.
            $vigente = ProfesionalVinculo::whereKey($vinculo->id)
                ->where('cuenta_id', $vinculo->cuenta_id)
                ->lockForUpdate()
                ->first();

            if ($cuenta === null
                || $vigente === null
                || ! $vigente->cuenta_creada_aqui
                || ! $cuenta->debe_cambiar_password
                || ProfesionalVinculo::where('cuenta_id', $cuenta->id)->where('id', '!=', $vigente->id)->exists()) {
                return null;
            }

            // Only the password and its tokens: `activo` and the flag stay as
            // they are, and no link is touched. The tokens left are restricted
            // ones issued for the old provisional password.
            $cuenta->forceFill(['password' => $hash])->save();
            $cuenta->tokens()->delete();

            return $cuenta;
        });

        if ($cuenta === null) {
            return;
        }

        // After the commit, like the invitation: an SMTP failure must not undo
        // the reset or turn into a 500 (the titular can simply reset again).
        try {
            Mail::to($cuenta->email)->send(new ProfesionalInvitacionMail(
                $titular->name,
                $profesional->nombre,
                $cuenta->email,
                $passwordProvisoria,
                (string) config('portal_profesionales.url'),
            ));
        } catch (\Throwable $e) {
            Log::error('No se pudo enviar el mail de contraseña provisoria del portal de profesionales', [
                'user_id' => $titular->id,
                'profesional_id' => $profesional->id,
                'email_hash' => sha1($cuenta->email),
                'excepcion' => $e::class,
            ]);
        }
    }
}

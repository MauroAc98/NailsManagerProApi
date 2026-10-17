<?php

namespace App\Services\Portal;

use App\Models\ProfesionalCuenta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Sets a new password for a portal account from the emailed recovery code.
 *
 * It answers only yes or no. Every "no" (unknown email, inactive account, no
 * code, expired, exhausted, wrong, malformed, lost a race) is the same to the
 * caller, and every "no" that gets as far as the comparison costs exactly one
 * hash check (against a throwaway hash when there is nothing real to compare).
 *
 * Two properties are what make a 6-digit code safe, and both survive parallel
 * requests because they are single SQL statements, not read-then-write:
 *  - the attempt is RESERVED (one conditional UPDATE: below the cap, not
 *    expired, same hash) BEFORE the comparison, so N parallel guesses can never
 *    get more than the cap in total;
 *  - the code is BURNED by a DELETE whose affected-row count must be 1, so one
 *    code can set the password once, even if two requests carry it.
 */
class RestablecerPasswordConCodigo
{
    // Bcrypt (cost 12, same as ProAuthController's) of a throwaway value, compared
    // against when there is no real code so the request costs the same. If the
    // prod bcrypt cost ever changes, change this hash too (a test pins the cost).
    private const HASH_FICTICIO = '$2y$12$.GwOtAfn1Lzq02J6ThckWOtXPOY2FwKvf2jAlmrht.aGF2SwA10/a';

    public function restablecer(string $email, string $codigo, string $password): bool
    {
        // Independent of any account: nothing can be learned from this branch,
        // and a typo must not spend an attempt of someone else's code.
        if (! preg_match('/\A\d{6}\z/', $codigo)) {
            return false;
        }

        $cuenta = ProfesionalCuenta::where('email', $email)->first();
        $fila = $cuenta?->activo
            ? DB::table(EmitirCodigoReset::TABLA)->where('cuenta_id', $cuenta->id)->first()
            : null;

        $reservado = $fila !== null && DB::table(EmitirCodigoReset::TABLA)
            ->where('id', $fila->id)
            ->where('code_hash', $fila->code_hash)
            ->where('intentos', '<', EmitirCodigoReset::MAX_INTENTOS)
            ->where('expira_en', '>', now()->timestamp)
            ->increment('intentos') === 1;

        $coincide = Hash::check($codigo, $reservado ? $fila->code_hash : self::HASH_FICTICIO);

        if (! $reservado || ! $coincide) {
            return false;
        }

        $hecho = DB::transaction(function () use ($cuenta, $fila, $password) {
            // Re-read under lock: an account switched off after we looked is not reset.
            $bloqueada = ProfesionalCuenta::whereKey($cuenta->id)->lockForUpdate()->first();

            if ($bloqueada === null || ! $bloqueada->activo) {
                return false;
            }

            $quemado = DB::table(EmitirCodigoReset::TABLA)
                ->where('id', $fila->id)
                ->where('code_hash', $fila->code_hash)
                ->delete();

            if ($quemado !== 1) {
                return false;
            }

            $bloqueada->forceFill(['password' => $password, 'debe_cambiar_password' => false])->save();
            // The old password may have been in someone else's hands: every session goes.
            $bloqueada->tokens()->delete();

            return true;
        });

        if ($hecho) {
            Log::info('RestablecerPasswordConCodigo: password reset done', ['cuenta_id' => $cuenta->id]);
        }

        return $hecho;
    }
}

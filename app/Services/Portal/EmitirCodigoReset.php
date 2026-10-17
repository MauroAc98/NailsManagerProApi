<?php

namespace App\Services\Portal;

use App\Mail\ProfesionalResetCodeMail;
use App\Models\ProfesionalCuenta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Issues the recovery code of a portal account. It runs AFTER the HTTP response
 * (see ProPasswordResetController::forgot), so whatever it does or skips is
 * invisible to the caller.
 *
 * It issues a code only to an active account with at least one active link,
 * and at most one per account every ENFRIAMIENTO_SEGUNDOS: the new code
 * replaces the old one and restarts its attempts, so that restart can happen no
 * faster than the cooldown allows. The code is generated and hashed before any
 * branching, so every path does the same hashing work.
 *
 * The code goes by email only: never in a response, never in a log.
 */
class EmitirCodigoReset
{
    public const VIGENCIA_SEGUNDOS = 30 * 60;

    public const ENFRIAMIENTO_SEGUNDOS = 60;

    /** Wait after a code whose attempts were all spent: a new code restarts them. */
    public const ENFRIAMIENTO_TRAS_AGOTAR_SEGUNDOS = 60 * 60;

    public const MAX_INTENTOS = 5;

    public const TABLA = 'profesional_cuenta_reset_codes';

    public function handle(string $email): void
    {
        $codigo = (string) random_int(100000, 999999);
        $hash = Hash::make($codigo);

        $cuenta = DB::transaction(function () use ($email, $hash) {
            // The row lock serialises two requests for the same account, so the
            // cooldown check and the write cannot interleave (Postgres).
            $cuenta = ProfesionalCuenta::where('email', $email)->lockForUpdate()->first();

            if ($cuenta === null || ! $cuenta->activo || ! $cuenta->tieneVinculoActivo()) {
                return null;
            }

            $ahora = now()->timestamp;
            $fila = DB::table(self::TABLA)->where('cuenta_id', $cuenta->id)->first();

            // After an exhausted code the wait is long: a new code restarts the
            // attempts, so a short wait would give an attacker 5 fresh guesses a minute.
            $espera = $fila !== null && (int) $fila->intentos >= self::MAX_INTENTOS
                ? self::ENFRIAMIENTO_TRAS_AGOTAR_SEGUNDOS
                : self::ENFRIAMIENTO_SEGUNDOS;

            if ($fila !== null && $ahora - (int) $fila->ultimo_envio_en < $espera) {
                return null;
            }

            $valores = [
                'code_hash' => $hash,
                'expira_en' => $ahora + self::VIGENCIA_SEGUNDOS,
                'ultimo_envio_en' => $ahora,
                'intentos' => 0,
                'updated_at' => now(),
            ];

            $fila === null
                ? DB::table(self::TABLA)->insert($valores + ['cuenta_id' => $cuenta->id, 'created_at' => now()])
                : DB::table(self::TABLA)->where('id', $fila->id)->update($valores);

            return $cuenta;
        });

        if ($cuenta === null) {
            return;
        }

        // After the commit: the SMTP round trip must not hold the account row.
        // A failure leaves the code in place; asking again works after the cooldown.
        try {
            Mail::to($cuenta->email)->send(new ProfesionalResetCodeMail($codigo, (string) config('portal_profesionales.url')));
        } catch (\Throwable $e) {
            Log::error('No se pudo enviar el código de recuperación del portal de profesionales', [
                'cuenta_id' => $cuenta->id,
                'email_hash' => sha1($cuenta->email),
                'excepcion' => $e::class,
            ]);
        }
    }
}

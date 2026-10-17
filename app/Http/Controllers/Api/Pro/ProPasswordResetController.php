<?php

namespace App\Http\Controllers\Api\Pro;

use App\Http\Controllers\Controller;
use App\Services\Portal\EmitirCodigoReset;
use App\Services\Portal\RestablecerPasswordConCodigo;
use App\Support\BloqueoLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Self-service password recovery of a portal account. Both routes are public
 * (a locked-out person has no token) and must reveal nothing about which emails
 * have an account: the answer, the work done before it and its timing are the
 * same for every email.
 */
class ProPasswordResetController extends Controller
{
    // ─────────────────────────────────────────────
    // POST /api/pro/forgot-password
    // Validation is the only thing that happens before the response, and it
    // does not look at the database. Everything else (lookup, code, mail) runs
    // after the response has been sent, so an existing and an unknown email
    // cost the same time.
    // ─────────────────────────────────────────────
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|string|email|max:255',
        ]);

        $email = strtolower(trim($data['email']));

        dispatch(fn () => app(EmitirCodigoReset::class)->handle($email))->afterResponse();

        return response()->json([
            'message' => 'Si el email corresponde a una cuenta del portal, te enviamos un código para recuperar la contraseña.',
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/pro/reset-password
    // Field validation first: it never looks at the account, so it neither
    // leaks nor spends an attempt of the code. Then ONE failure for everything
    // that can go wrong with the code or the account. No token is issued: the
    // person signs in again with the new password.
    // ─────────────────────────────────────────────
    public function reset(Request $request, RestablecerPasswordConCodigo $restablecer): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|string|email|max:255',
            'code' => 'required|string|max:32',
            'password' => 'required|string|min:10|max:255|confirmed',
        ], [
            'password.required' => 'Ingresá la nueva contraseña.',
            'password.string' => 'La contraseña no es válida.',
            'password.min' => 'La contraseña debe tener al menos 10 caracteres.',
            'password.max' => 'La contraseña es demasiado larga.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        $email = strtolower(trim($data['email']));

        if (! $restablecer->restablecer($email, $data['code'], $data['password'])) {
            throw ValidationException::withMessages([
                'code' => ['El código es incorrecto o venció. Pedí uno nuevo.'],
            ]);
        }

        // Whoever proved control of the mailbox may sign in again from this
        // address; the lockout of that email + IP pair does not outlive the reset.
        BloqueoLogin::profesional()->limpiar($email, $request->ip());

        return response()->json([
            'message' => 'Listo, cambiaste tu contraseña. Ingresá con la nueva.',
        ]);
    }
}

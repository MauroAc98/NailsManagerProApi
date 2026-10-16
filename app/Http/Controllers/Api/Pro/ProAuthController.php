<?php

namespace App\Http\Controllers\Api\Pro;

use App\Http\Controllers\Controller;
use App\Models\ProfesionalCuenta;
use App\Services\Portal\EmisorTokenProfesional;
use App\Support\BloqueoLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ProAuthController extends Controller
{
    // Bcrypt hash (cost 12) of a throwaway value, compared against when the
    // email does not exist so the response takes as long as with a real
    // account. Same cost as AdminAuthController::HASH_FICTICIO and as the
    // default of config/hashing.php (BCRYPT_ROUNDS is not set in prod): if the
    // prod cost ever changes, change this hash too (a test pins the cost).
    private const HASH_FICTICIO = '$2y$12$kxCm.0XwWSgO/cCPMLdiDO6r57wjgNolZyYnGQOpyakhOifvRFaTe';

    private const MENSAJE_CREDENCIALES = 'Las credenciales son incorrectas.';

    // ─────────────────────────────────────────────
    // POST /api/pro/login
    // Mirrors AdminAuthController::login: lockout first, a hash check that
    // always runs, one identical 401 for every failure. The "has an active
    // link" query runs only AFTER the password matched, so it cannot become a
    // timing or existence signal for wrong credentials.
    // ─────────────────────────────────────────────
    public function login(Request $request, EmisorTokenProfesional $emisor): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|max:255',
            // Accepted for the client contract; not persisted (the token name
            // groups the device cap, see EmisorTokenProfesional).
            'dispositivo' => 'nullable|string|max:100',
        ]);

        $email = strtolower(trim($data['email']));
        $ip = $request->ip();
        $bloqueo = BloqueoLogin::profesional();

        if (($espera = $bloqueo->segundosDeBloqueo($email, $ip)) !== null) {
            Log::warning('ProAuthController: login blocked after too many failures', ['email_hash' => sha1($email)]);

            return response()->json([
                'message' => 'Demasiados intentos fallidos. Probá de nuevo en unos minutos.',
                'retry_after' => $espera,
            ], 429, ['Retry-After' => $espera]);
        }

        $cuenta = ProfesionalCuenta::where('email', $email)->first();

        // The hash check always runs, whether the account exists or not.
        $passwordOk = Hash::check($data['password'], $cuenta?->password ?? self::HASH_FICTICIO);

        if (! $cuenta || ! $passwordOk || ! $cuenta->activo || ! $this->tieneVinculoActivo($cuenta)) {
            $bloqueo->registrarFallo($email, $ip);
            // Never the typed email or password: only whether the account existed.
            Log::warning('ProAuthController: failed login attempt', [
                'email_hash' => sha1($email),
                'email_existe' => $cuenta !== null,
            ]);

            return response()->json(['message' => self::MENSAJE_CREDENCIALES], 401);
        }

        $bloqueo->limpiar($email, $ip);

        $cuenta->forceFill(['ultimo_login_at' => now(), 'ultimo_login_ip' => $ip])->save();

        // A provisional password never yields a full token: only the restricted
        // one that can reach `cambiar-password`.
        $debeCambiar = $cuenta->debe_cambiar_password;
        $emitido = $debeCambiar ? $emisor->restringido($cuenta) : $emisor->completo($cuenta);

        Log::info('ProAuthController: login ok', ['cuenta_id' => $cuenta->id]);

        return response()->json([
            'token' => $emitido['token'],
            'expires_at' => $emitido['expires_at'],
            'debe_cambiar_password' => $debeCambiar,
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/pro/me
    // Stub for now: just the account. The chooser payload (links, negocio,
    // professional) arrives with the per-request validity slice.
    // ─────────────────────────────────────────────
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'cuenta' => ['email' => $request->user('profesional')->email],
        ]);
    }

    // Short-circuit order matters: this query only runs once the password
    // matched and the account is active (see login()).
    private function tieneVinculoActivo(ProfesionalCuenta $cuenta): bool
    {
        return $cuenta->vinculos()
            ->activos()
            ->whereHas('profesional', fn ($q) => $q->where('activo', true))
            ->exists();
    }
}

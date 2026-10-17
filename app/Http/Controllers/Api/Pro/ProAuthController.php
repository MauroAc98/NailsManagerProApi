<?php

namespace App\Http\Controllers\Api\Pro;

use App\Http\Controllers\Controller;
use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
use App\Services\Portal\EmisorTokenProfesional;
use App\Support\BloqueoLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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

        // Short-circuit order matters: the link query only runs once the
        // password matched and the account is active.
        if (! $cuenta || ! $passwordOk || ! $cuenta->activo || ! $cuenta->tieneVinculoActivo()) {
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
    // POST /api/pro/logout
    // Revokes only the presented token; the account's other devices keep
    // working.
    // ─────────────────────────────────────────────
    public function logout(Request $request): Response
    {
        $request->user('profesional')->currentAccessToken()->delete();

        return response()->noContent();
    }

    // ─────────────────────────────────────────────
    // GET /api/pro/me
    // The negocio chooser: the account plus its ACTIVE links to ACTIVE
    // professionals, by link id. A link whose negocio subscription lapsed is
    // still listed, as `disponible:false` with the same code the tenant gate
    // uses, so the app can explain it instead of silently hiding a negocio.
    // Explicit arrays only: no model is serialized, so nothing else can leak.
    // ─────────────────────────────────────────────
    public function me(Request $request): JsonResponse
    {
        $cuenta = $request->user('profesional');

        $vinculos = $cuenta->vinculos()
            ->activos()
            ->whereHas('profesional', fn ($q) => $q->where('activo', true))
            ->with([
                'profesional:id,user_id,nombre,apellido,avatar_path',
                'profesional.user:id,name,is_exempt',
                'profesional.user.subscription',
            ])
            ->orderBy('id')
            ->get();

        return response()->json([
            'cuenta' => ['email' => $cuenta->email],
            'vinculos' => $vinculos->map(function (ProfesionalVinculo $vinculo) {
                $profesional = $vinculo->profesional;
                $motivo = $profesional->user->codigoDeBloqueoPorSuscripcion();

                return [
                    'id' => $vinculo->id,
                    'negocio' => ['nombre' => $profesional->user->name],
                    'profesional' => [
                        'nombre' => $profesional->nombre,
                        'apellido' => $profesional->apellido,
                        'avatar_url' => $profesional->avatar_url,
                    ],
                    'disponible' => $motivo === null,
                    'motivo' => $motivo,
                ];
            })->all(),
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/pro/cambiar-password
    // The forced change after a provisional password. Reachable ONLY with the
    // restricted token (route ability `pro:cambiar-password`), which login
    // hands out after a correct password: there is no unauthenticated variant
    // and no "current password" field to brute-force, because the token IS the
    // proof of knowing it. On success every token of the account is revoked
    // (the restricted one included) and one fresh full token is issued the same
    // way login does, device cap included.
    // ─────────────────────────────────────────────
    public function cambiarPassword(Request $request, EmisorTokenProfesional $emisor): JsonResponse
    {
        $data = $request->validate([
            'password' => 'required|string|min:10|max:255|confirmed',
        ], [
            'password.required' => 'Ingresá la nueva contraseña.',
            'password.string' => 'La contraseña no es válida.',
            'password.min' => 'La contraseña debe tener al menos 10 caracteres.',
            'password.max' => 'La contraseña es demasiado larga.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        $cuenta = $request->user('profesional');

        // The stored hash is the provisional password at this point.
        if (Hash::check($data['password'], $cuenta->password)) {
            throw ValidationException::withMessages([
                'password' => ['La nueva contraseña tiene que ser distinta de la provisoria.'],
            ]);
        }

        $emitido = DB::transaction(function () use ($cuenta, $data, $emisor) {
            // Re-read under lock: two requests with the same restricted token
            // cannot both rewrite the password, and a flag cleared after the
            // token was issued is honoured.
            $bloqueada = ProfesionalCuenta::whereKey($cuenta->id)->lockForUpdate()->first();

            if (! $bloqueada->debe_cambiar_password) {
                return null;
            }

            $bloqueada->forceFill([
                'password' => $data['password'],
                'debe_cambiar_password' => false,
            ])->save();
            $bloqueada->tokens()->delete();

            return $emisor->completo($bloqueada);
        });

        if ($emitido === null) {
            return response()->json([
                'message' => 'Esta cuenta no necesita cambiar la contraseña.',
                'code' => 'PASSWORD_CAMBIO_NO_REQUERIDO',
            ], 403);
        }

        Log::info('ProAuthController: forced password change done', ['cuenta_id' => $cuenta->id]);

        return response()->json([
            'token' => $emitido['token'],
            'expires_at' => $emitido['expires_at'],
            'debe_cambiar_password' => false,
        ]);
    }
}

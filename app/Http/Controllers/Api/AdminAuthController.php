<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\AdminAudit;
use App\Support\BloqueoLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class AdminAuthController extends Controller
{
    // Largo mínimo de contraseña del admin (más estricto que el de los negocios).
    public const PASSWORD_MIN = 12;

    // Hash bcrypt (cost 12) de un valor descartable. Cuando el email no existe
    // se compara contra este hash para que la respuesta tarde lo mismo que con
    // un admin real y no se pueda enumerar cuentas midiendo tiempos.
    private const HASH_FICTICIO = '$2y$12$d5KCggYkRlfvOxHcnNHDGuigo48fp4MXWpMNhQDTm4Bp2LoLDAwUi';

    // ─────────────────────────────────────────────
    // POST /api/admin/login
    // Identidad disjunta de auth/login (tenant) — ver AdminUser y
    // config/auth.php. 401 explícito en vez de ValidationException (422):
    // la spec de admin-identity exige 401 acá, distinto de la convención
    // de AuthController::login.
    // ─────────────────────────────────────────────
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $email = strtolower($data['email']);
        $ip = $request->ip();
        $bloqueo = BloqueoLogin::admin();

        // El admin es la cuenta de mayor privilegio y no tiene segundo factor:
        // bloqueo temporal mas estricto que el de los negocios. Es por (email, IP)
        // y por IP, nunca por cuenta a secas: ver BloqueoLogin::admin().
        if (($espera = $bloqueo->segundosDeBloqueo($email, $ip)) !== null) {
            Log::warning('AdminAuthController: login bloqueado por demasiados fallos', ['email_hash' => sha1($email)]);
            AdminAudit::record(null, 'login.bloqueado', payload: ['retry_after' => $espera], request: $request);

            return response()->json([
                'message' => 'Demasiados intentos fallidos. Probá de nuevo en unos minutos.',
                'retry_after' => $espera,
            ], 429);
        }

        $admin = AdminUser::where('email', $email)->first();

        // Hash::check siempre se ejecuta, exista o no el admin (timing).
        $passwordOk = Hash::check($data['password'], $admin?->password ?? self::HASH_FICTICIO);

        // Mismo 401 para email inexistente, password incorrecta y admin
        // desactivado: no se revela cuál de los tres fue.
        if (! $admin || ! $passwordOk || ! $admin->activo) {
            $bloqueo->registrarFallo($email, $ip);
            Log::warning('AdminAuthController: intento de login fallido', [
                'email_hash' => sha1($email),
                'email_existe' => $admin !== null,
            ]);
            // Nunca el email ni la contraseña tipeados: solo si la cuenta existía.
            AdminAudit::record(null, 'login.fallido', payload: ['email_existe' => $admin !== null], request: $request);

            return response()->json(['message' => 'Las credenciales son incorrectas.'], 401);
        }

        $bloqueo->limpiar($email, $ip);

        // Un solo admin activo por vez: cada login invalida sesiones
        // previas, igual criterio que AuthController::login para tenants.
        $admin->tokens()->delete();

        $expiresAt = now()->addHours(12);
        $token = $admin->createToken('admin-panel', ['*'], $expiresAt)->plainTextToken;

        Log::info('AdminAuthController: login exitoso', ['admin_id' => $admin->id]);
        AdminAudit::record($admin, 'login.exitoso', request: $request);

        return response()->json([
            'admin' => $admin,
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/admin/logout
    // ─────────────────────────────────────────────
    public function logout(Request $request): JsonResponse
    {
        AdminAudit::record($request->user('admin'), 'logout', request: $request);

        $request->user('admin')->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/admin/me
    // ─────────────────────────────────────────────
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user('admin'));
    }

    // ─────────────────────────────────────────────
    // POST /api/admin/cambiar-password
    // Cambia la contraseña del admin autenticado y revoca el resto de sus
    // sesiones (conserva la actual, para no sacarlo del panel).
    // ─────────────────────────────────────────────
    public function cambiarPassword(Request $request): JsonResponse
    {
        $admin = $request->user('admin');

        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:' . self::PASSWORD_MIN . '|confirmed|different:current_password',
        ]);

        if (! Hash::check($data['current_password'], $admin->password)) {
            return response()->json([
                'message' => 'La contraseña actual es incorrecta.',
                'errors' => ['current_password' => ['La contraseña actual es incorrecta.']],
            ], 422);
        }

        $admin->update(['password' => $data['password']]);

        $actual = $admin->currentAccessToken();
        $otros = $admin->tokens();
        if ($actual instanceof PersonalAccessToken) {
            $otros->where('id', '!=', $actual->id);
        }
        $otros->delete();

        AdminAudit::record($admin, 'password.cambiada', request: $request);

        return response()->json(['message' => 'Contraseña actualizada.']);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AdminAuthController extends Controller
{
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

        $admin = AdminUser::where('email', strtolower($data['email']))->first();

        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            Log::warning('AdminAuthController: intento de login fallido', ['email' => $data['email']]);

            return response()->json(['message' => 'Las credenciales son incorrectas.'], 401);
        }

        // Un solo admin activo por vez: cada login invalida sesiones
        // previas, igual criterio que AuthController::login para tenants.
        $admin->tokens()->delete();

        $expiresAt = now()->addHours(12);
        $token = $admin->createToken('admin-panel', ['*'], $expiresAt)->plainTextToken;

        Log::info('AdminAuthController: login exitoso', ['admin_id' => $admin->id]);

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
}

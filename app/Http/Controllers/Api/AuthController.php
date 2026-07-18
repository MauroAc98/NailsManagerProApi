<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ProvisionalPasswordMail;
use App\Mail\ResetCodeMail;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // ─────────────────────────────────────────────
    // POST /api/auth/register
    // ─────────────────────────────────────────────
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
        ]);

        $passwordProvisoria = Str::password(10, symbols: false);

        $user = User::create([
            'name'                  => $data['name'],
            'email'                 => strtolower($data['email']),
            'password'              => $passwordProvisoria,
            'debe_cambiar_password' => true,
        ]);

        \App\Models\Subscription::create([
            'user_id'  => $user->id,
            'ends_at'  => now()->addDays(30),
            'status'   => 'ACTIVO',
        ]);

        \App\Models\Profesional::create([
            'user_id' => $user->id,
            'nombre'  => $user->name,
            'activo'  => true,
        ]);

        Mail::to($user->email)->send(
            new ProvisionalPasswordMail($user->name, $user->email, $passwordProvisoria),
        );

        return response()->json([
            'message'             => 'Usuario creado. Se envió la contraseña provisoria por email.',
            'user'                => $user,
            'password_provisoria' => $passwordProvisoria,
        ], 201);
    }

    // ─────────────────────────────────────────────
    // POST /api/auth/login
    // ─────────────────────────────────────────────
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        if ($user->debe_cambiar_password) {
            return response()->json([
                'debe_cambiar_password' => true,
                'email'                 => $user->email,
                'message'               => 'Tenés que establecer una nueva contraseña antes de continuar.',
            ], 200);
        }

        if ($request->filled('fcm_token')) {
            $user->update(['fcm_token' => $request->fcm_token]);
        }

        $user->tokens()->delete();
        $token = $user->createToken('app-mobile')->plainTextToken;

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/auth/cambiar-password-obligatorio
    // ─────────────────────────────────────────────
    public function cambiarPasswordObligatorio(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'            => 'required|email',
            'password_actual'  => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        if (!$user || !Hash::check($data['password_actual'], $user->password)) {
            throw ValidationException::withMessages([
                'password_actual' => ['La contraseña actual es incorrecta.'],
            ]);
        }

        if (!$user->debe_cambiar_password) {
            return response()->json([
                'message' => 'Esta cuenta no requiere cambio de contraseña obligatorio.',
            ], 422);
        }

        $user->update([
            'password'               => bcrypt($data['password']),
            'debe_cambiar_password'  => false,
        ]);

        $user->tokens()->delete();
        $token = $user->createToken('app-mobile')->plainTextToken;

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/auth/logout
    // ─────────────────────────────────────────────
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/auth/me
    // ─────────────────────────────────────────────
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    // ─────────────────────────────────────────────
    // PUT /api/perfil
    // ─────────────────────────────────────────────
    public function updatePerfil(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'                    => 'sometimes|string|max:255',
            'telefono'                => 'sometimes|nullable|string|max:30',
            'direccion'               => 'sometimes|nullable|string|max:255',
            'recordatorio_automatico' => 'sometimes|boolean',
            'hora_recordatorio'       => 'sometimes|string|in:18:00,19:00,20:00,21:00,22:00',
            'sena_monto'              => 'sometimes|nullable|numeric|min:0',
            'fcm_token'               => 'sometimes|nullable|string',
            'password'                => 'sometimes|string|min:8|confirmed',
        ]);

        if (isset($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        }

        $user->update($data);

        return response()->json($user);
    }

    // ─────────────────────────────────────────────
    // POST /api/auth/forgot-password
    // ─────────────────────────────────────────────
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $email = strtolower($data['email']);
        $code = (string) random_int(100000, 999999);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token'      => Hash::make($code),
                'created_at' => now(),
            ],
        );

        Mail::to($email)->send(new ResetCodeMail($code));

        return response()->json([
            'message' => 'Te enviamos un código a tu email.',
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/auth/reset-password
    // ─────────────────────────────────────────────
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email|exists:users,email',
            'code'     => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $email = strtolower($data['email']);

        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (!$record || !Hash::check($data['code'], $record->token)) {
            throw ValidationException::withMessages([
                'code' => ['El código ingresado es incorrecto.'],
            ]);
        }

        if (now()->diffInMinutes($record->created_at) > 30) {
            throw ValidationException::withMessages([
                'code' => ['El código expiró. Solicitá uno nuevo.'],
            ]);
        }

        $user = User::where('email', $email)->first();
        $user->update([
            'password'              => bcrypt($data['password']),
            'debe_cambiar_password' => false,
        ]);

        $user->tokens()->delete();

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json([
            'message' => 'Contraseña actualizada correctamente.',
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/auth/subscription-status
    // ─────────────────────────────────────────────
    public function subscriptionStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->is_exempt) {
            return response()->json([
                'status'    => 'ACTIVO',
                'is_exempt' => true,
                'ends_at'   => null,
                'days_left' => null,
            ]);
        }

        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json([
                'status'    => 'VENCIDO',
                'is_exempt' => false,
                'ends_at'   => null,
                'days_left' => 0,
            ]);
        }

        $daysLeft = max(0, (int) now()->diffInDays($subscription->ends_at, false));

        return response()->json([
            'status'    => $subscription->ends_at > now() ? 'ACTIVO' : 'VENCIDO',
            'is_exempt' => false,
            'ends_at'   => $subscription->ends_at,
            'days_left' => $daysLeft,
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/support-info
    // Público — no requiere autenticación
    // ─────────────────────────────────────────────
    public function supportInfo(): JsonResponse
    {
        return response()->json([
            'whatsapp'                  => Setting::get('support_whatsapp'),
            'email'                     => Setting::get('support_email'),
            'subscription_warning_days' => (int) Setting::get('subscription_warning_days'),
        ]);
    }
}

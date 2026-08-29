<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ResetCodeMail;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // register() se mudó a AdminController::crearNegocio() — ver
    // POST /api/admin/negocios. La creación de negocios ahora exige sesión
    // admin (auth:admin) en vez de la ruta pública auth/register.

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
            'confirmacion_automatica' => 'sometimes|boolean',
            'hora_recordatorio'       => 'sometimes|string|in:18:00,19:00,20:00,21:00,22:00',
            'sena_monto'              => 'sometimes|nullable|numeric|min:0',
            'fcm_token'               => 'sometimes|nullable|string',
            'password'                => 'sometimes|string|min:8|confirmed',
            'locale'                  => 'sometimes|nullable|in:es,pt-BR,en',
        ]);

        if (isset($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        }

        // Los envíos automáticos de WhatsApp (confirmación/recordatorio) ya
        // incluyen la dirección como parámetro fijo de la plantilla Meta —
        // sin dirección cargada Meta rechaza el envío completo. Evita que
        // alguien active el toggle sin haber cargado el dato antes.
        //
        // Solo dispara si la request TOCA alguno de los dos toggles — no
        // alcanza con mirar el estado final, porque confirmacion_automatica
        // es true por default en cuentas nuevas (ver User::$attributes) y
        // eso bloquearía cualquier edición de perfil sin relación (cambiar
        // locale, contraseña, etc.) en una cuenta que todavía no cargó
        // dirección.
        $tocaAutomaticos = array_key_exists('confirmacion_automatica', $data) || array_key_exists('recordatorio_automatico', $data);

        if ($tocaAutomaticos) {
            $confirmacionFinal = $data['confirmacion_automatica'] ?? $user->confirmacion_automatica;
            $recordatorioFinal = $data['recordatorio_automatico'] ?? $user->recordatorio_automatico;
            $direccionFinal = array_key_exists('direccion', $data) ? $data['direccion'] : $user->direccion;

            if (($confirmacionFinal || $recordatorioFinal) && blank($direccionFinal)) {
                throw ValidationException::withMessages([
                    'direccion' => ['Cargá tu dirección antes de activar los envíos automáticos de WhatsApp.'],
                ]);
            }
        }

        $user->update($data);

        return response()->json($user);
    }

    // ─────────────────────────────────────────────
    // POST /api/perfil/logo
    // Sube (o reemplaza) el logo del negocio, usado en el login
    // personalizado por slug. Mismo mecanismo de storage que
    // ProfesionalController::subirFondoHistoria: disco 'public', borra el
    // archivo anterior antes de guardar el nuevo para no dejar huérfanos.
    // ─────────────────────────────────────────────
    public function subirLogo(Request $request): JsonResponse
    {
        // mimes explícito (no solo 'image'): la regla 'image' de Laravel
        // acepta SVG, que puede traer <script> embebido — el logo se sirve
        // público y sin auth en GET /public/{slug}/branding, así que un SVG
        // malicioso ahí es XSS servido directo a cualquier visitante.
        $request->validate([
            'imagen' => 'required|image|mimes:jpeg,png,jpg,webp,gif,bmp|max:5120', // 5MB
        ]);

        $user = $request->user();
        $pathAnterior = $user->getRawOriginal('logo_path');

        // Subir y confirmar ANTES de tocar el archivo viejo: si store()
        // falla (disco lleno, permisos), el logo anterior tiene que seguir
        // sirviendo en vez de quedar el usuario sin logo y sin forma de
        // saber que la subida no se completó.
        $path = $request->file('imagen')->store('logos', 'public');
        if (!$path) {
            return response()->json(['message' => 'No se pudo guardar el logo. Intentá de nuevo.'], 500);
        }

        $user->update(['logo_path' => $path]);

        if ($pathAnterior) {
            Storage::disk('public')->delete($pathAnterior);
        }

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
                'code'      => null,
            ]);
        }

        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json([
                'status'    => 'VENCIDO',
                'is_exempt' => false,
                'ends_at'   => null,
                'days_left' => 0,
                'code'      => 'NO_SUBSCRIPTION',
            ]);
        }

        $daysLeft = max(0, (int) now()->diffInDays($subscription->ends_at, false));

        // SUSPENDIDO es el único status guardado que no se deriva de ends_at:
        // se devuelve tal cual para que un dueño cortado vea el motivo real
        // (este endpoint NO pasa por CheckSubscription). `code` espeja el
        // mismo vocabulario que CheckSubscription y es puramente aditivo —
        // el frontend sigue gateando el bloqueo por `status !== 'ACTIVO'`.
        $suspendida = $subscription->status === 'SUSPENDIDO';
        $vencida    = $subscription->ends_at <= now();

        return response()->json([
            'status'    => $suspendida
                ? 'SUSPENDIDO'
                : ($subscription->ends_at > now() ? 'ACTIVO' : 'VENCIDO'),
            'is_exempt' => false,
            'ends_at'   => $subscription->ends_at,
            'days_left' => $daysLeft,
            'code'      => match (true) {
                $suspendida => 'SUBSCRIPTION_SUSPENDED',
                $vencida    => 'SUBSCRIPTION_EXPIRED',
                default     => null,
            },
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

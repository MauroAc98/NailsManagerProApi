<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ResetCodeMail;
use App\Models\Setting;
use App\Models\User;
use App\Support\BloqueoLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $email = strtolower($data['email']);
        $bloqueo = BloqueoLogin::negocio();

        // Bloqueo temporal por cuenta tras varios fallos (el throttle por IP de
        // la ruta no frena a quien rota IPs).
        if (($espera = $bloqueo->segundosDeBloqueo($email, $request->ip())) !== null) {
            return response()->json([
                'message' => 'Demasiados intentos fallidos. Probá de nuevo en unos minutos.',
                'retry_after' => $espera,
            ], 429);
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $bloqueo->registrarFallo($email, $request->ip());

            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        $bloqueo->limpiar($email, $request->ip());

        if ($user->debe_cambiar_password) {
            return response()->json([
                'debe_cambiar_password' => true,
                'email' => $user->email,
                'message' => 'Tenés que establecer una nueva contraseña antes de continuar.',
            ], 200);
        }

        if ($request->filled('fcm_token')) {
            $user->update(['fcm_token' => $request->fcm_token]);
        }

        $user->tokens()->delete();
        $token = $user->createToken('app-mobile')->plainTextToken;

        return response()->json([
            'user' => $user->append('comision_mp_vigente'),
            'token' => $token,
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/auth/cambiar-password-obligatorio
    // ─────────────────────────────────────────────
    public function cambiarPasswordObligatorio(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password_actual' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        if (! $user || ! Hash::check($data['password_actual'], $user->password)) {
            throw ValidationException::withMessages([
                'password_actual' => ['La contraseña actual es incorrecta.'],
            ]);
        }

        if (! $user->debe_cambiar_password) {
            return response()->json([
                'message' => 'Esta cuenta no requiere cambio de contraseña obligatorio.',
            ], 422);
        }

        $user->update([
            'password' => bcrypt($data['password']),
            'debe_cambiar_password' => false,
        ]);

        $user->tokens()->delete();
        $token = $user->createToken('app-mobile')->plainTextToken;

        return response()->json([
            'user' => $user->append('comision_mp_vigente'),
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
        return response()->json($request->user()->append('comision_mp_vigente'));
    }

    // ─────────────────────────────────────────────
    // PUT /api/perfil
    // ─────────────────────────────────────────────
    public function updatePerfil(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'telefono' => 'sometimes|nullable|string|max:30',
            'direccion' => 'sometimes|nullable|string|max:255',
            // required_with solo no alcanza: con nullable, {latitud: -27.4,
            // longitud: null} pasa esta regla porque la key longitud SÍ está
            // presente. El guard explícito de abajo cubre ese caso.
            'latitud' => 'sometimes|nullable|numeric|between:-90,90|required_with:longitud',
            'longitud' => 'sometimes|nullable|numeric|between:-180,180|required_with:latitud',
            'recordatorio_automatico' => 'sometimes|boolean',
            'confirmacion_automatica' => 'sometimes|boolean',
            'hora_recordatorio' => 'sometimes|string|in:18:00,19:00,20:00,21:00,22:00',
            'sena_monto' => 'sometimes|nullable|numeric|min:0',
            // Modo de la seña: monto fijo (sena_monto) o % del total de la
            // reserva. El rango 1..100 se exige abajo solo si el modo final
            // es porcentaje.
            'sena_tipo' => 'sometimes|in:fijo,porcentaje',
            'sena_porcentaje' => 'sometimes|nullable|numeric|min:0|max:100',
            // Retencion de Ingresos Brutos que MP le aplica al negocio: dato
            // informativo para el preview, no cambia lo que se cobra.
            'retencion_iibb_porcentaje' => 'sometimes|nullable|numeric|min:0|max:50',
            'whatsapp_pide_sena' => 'sometimes|boolean',
            // not_regex: los datos bancarios viajan como parámetros de la
            // plantilla Meta reserva_turno_sena — un salto de línea o tab
            // hace que Meta rechace el envío completo.
            'whatsapp_sena_titular' => 'sometimes|nullable|string|max:120|not_regex:/[\r\n\t]/',
            'whatsapp_sena_entidad' => 'sometimes|nullable|string|max:120|not_regex:/[\r\n\t]/',
            'whatsapp_sena_alias' => 'sometimes|nullable|string|max:60|not_regex:/[\r\n\t]/',
            'whatsapp_sena_cbu' => 'sometimes|nullable|string|max:34|not_regex:/[\r\n\t]/',
            'fcm_token' => 'sometimes|nullable|string',
            'password' => 'sometimes|string|min:8|confirmed',
            'locale' => 'sometimes|nullable|in:es,pt-BR,en',
            'atiende_en_paralelo' => 'sometimes|boolean',
            // Categorías personalizadas de gastos / ingresos. Lista completa
            // (reemplaza el set actual del usuario), 1..30 ítems, cada nombre
            // string no vacío de hasta 40 chars y sin repetir. regex:/\S/
            // rechaza strings de puro espacio que trim() dejaría vacíos.
            'categorias_gasto' => 'sometimes|array|min:1|max:30',
            'categorias_gasto.*' => 'required|string|max:40|distinct|regex:/\S/',
            'categorias_ingreso' => 'sometimes|array|min:1|max:30',
            'categorias_ingreso.*' => 'required|string|max:40|distinct|regex:/\S/',
        ]);

        if (isset($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        }

        // Guard de par de coordenadas: latitud y longitud tienen que llegar
        // ambas o ninguna. required_with (arriba) no alcanza porque con
        // nullable la key SÍ está presente aunque valga null. Se evalúa el
        // estado FINAL (valor de la request ?? valor actual del usuario)
        // para no rechazar una edición que solo toca una de las dos cuando
        // la otra ya estaba guardada de antes.
        $tocaUbicacion = array_key_exists('latitud', $data) || array_key_exists('longitud', $data);

        if ($tocaUbicacion) {
            $latFinal = array_key_exists('latitud', $data) ? $data['latitud'] : $user->latitud;
            $lonFinal = array_key_exists('longitud', $data) ? $data['longitud'] : $user->longitud;

            if (is_null($latFinal) !== is_null($lonFinal)) {
                throw ValidationException::withMessages([
                    'latitud' => ['Guardá la ubicación completa: faltan coordenadas.'],
                ]);
            }
        }

        // Normaliza cada categoría igual que un campo de línea simple: recorta
        // los extremos y colapsa espacios internos. Tras normalizar puede
        // aparecer un duplicado que `distinct` (que mira el valor crudo) no
        // detectó — se rechaza con un error sobre el campo.
        foreach (['categorias_gasto', 'categorias_ingreso'] as $campoCategorias) {
            if (! array_key_exists($campoCategorias, $data)) {
                continue;
            }

            $normalizadas = array_map(
                fn (string $nombre) => trim(preg_replace('/\s+/u', ' ', $nombre)),
                $data[$campoCategorias],
            );

            if (count(array_unique($normalizadas)) !== count($normalizadas)) {
                throw ValidationException::withMessages([
                    $campoCategorias => ['No se permiten categorías repetidas.'],
                ]);
            }

            $data[$campoCategorias] = array_values($normalizadas);
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
            $latFinalAuto = array_key_exists('latitud', $data) ? $data['latitud'] : $user->latitud;
            $lonFinalAuto = array_key_exists('longitud', $data) ? $data['longitud'] : $user->longitud;

            if (($confirmacionFinal || $recordatorioFinal) && blank($direccionFinal)) {
                throw ValidationException::withMessages([
                    'direccion' => ['Cargá tu dirección antes de activar los envíos automáticos de WhatsApp.'],
                ]);
            }

            // §Decision #691: el mismo gate de "falta ubicación" que ya
            // aplica a la seña (más abajo) bloquea también estos dos
            // toggles — ningún salón puede activar ningún envío automático
            // sin haber cargado lat/long primero.
            if (($confirmacionFinal || $recordatorioFinal) && (is_null($latFinalAuto) || is_null($lonFinalAuto))) {
                throw ValidationException::withMessages([
                    'latitud' => ['Marcá tu ubicación en el mapa antes de activar los envíos automáticos de WhatsApp.'],
                ]);
            }
        }

        // Guard de la seña: pedir seña en las confirmaciones solo tiene
        // sentido si el cliente sabe cuánto y a dónde pagar. Si la request
        // toca cualquiera de los campos de seña, se evalúa el estado FINAL
        // (valor de la request ?? valor actual del usuario) y, si queda
        // pidiendo seña, se exige monto > 0 + titular + (alias o CBU).
        // Apagar el toggle no borra nada — los datos bancarios quedan
        // guardados para reactivarlo sin recargarlos.
        $camposSena = ['whatsapp_pide_sena', 'sena_monto', 'sena_tipo', 'sena_porcentaje', 'whatsapp_sena_titular', 'whatsapp_sena_entidad', 'whatsapp_sena_alias', 'whatsapp_sena_cbu'];
        $tocaSena = (bool) array_intersect($camposSena, array_keys($data));

        if ($tocaSena) {
            $valorFinal = fn (string $campo) => array_key_exists($campo, $data) ? $data[$campo] : $user->{$campo};

            // Guard de Mercado Pago: con la cuenta conectada, la reserva
            // online depende de esta seña para poder cobrar (ver
            // PublicController::info -> pago_habilitado). Vaciarla sin darse
            // cuenta dejaría el flujo bloqueado para cualquier clienta nueva
            // sin ningún aviso — se corta acá antes de guardar.
            // Seña completa segun el modo FINAL: fijo = sena_monto > 0,
            // porcentaje = sena_porcentaje > 0 (el campo del otro modo no cuenta).
            $esPorcentaje = $valorFinal('sena_tipo') === 'porcentaje';
            $campoSena = $esPorcentaje ? 'sena_porcentaje' : 'sena_monto';
            $valorSena = $valorFinal($campoSena);
            $senaCompleta = is_numeric($valorSena) && (float) $valorSena > 0;

            // En modo porcentaje el valor tiene que estar entre 1 y 100.
            // Vaciarlo con MP conectado lo cubre el guard de abajo (mensaje propio).
            $porcentajeVacio = ! is_numeric($valorSena) || (float) $valorSena <= 0;
            $fueraDeRango = is_numeric($valorSena) && ((float) $valorSena < 1 || (float) $valorSena > 100);
            if ($esPorcentaje && ($fueraDeRango || ($porcentajeVacio && $user->mpCredentials === null))) {
                throw ValidationException::withMessages([
                    'sena_porcentaje' => ['El porcentaje de la seña tiene que estar entre 1 y 100.'],
                ]);
            }

            if (! $senaCompleta && $user->mpCredentials !== null) {
                throw ValidationException::withMessages([
                    $campoSena => ['No podés vaciar la seña: tenés Mercado Pago conectado y la reserva online la necesita para cobrar.'],
                ]);
            }

            // La plantilla de WhatsApp de la seña (reserva_turno_sena) solo
            // soporta un monto fijo hasta una slice posterior.
            if ($esPorcentaje && $valorFinal('whatsapp_pide_sena')) {
                throw ValidationException::withMessages([
                    'whatsapp_pide_sena' => ['Con la seña en porcentaje todavía no se puede pedir la seña en las confirmaciones de WhatsApp: ese mensaje solo admite un monto fijo.'],
                ]);
            }

            if ($valorFinal('whatsapp_pide_sena')) {
                $errores = [];

                if (! $senaCompleta) {
                    $errores[$campoSena] = [$esPorcentaje
                        ? 'Cargá el porcentaje de la seña para pedirla en las confirmaciones.'
                        : 'Cargá el monto de la seña para pedirla en las confirmaciones.'];
                }

                // direccion es el parámetro fijo {{6}} de reserva_turno_sena.
                if (blank($valorFinal('direccion'))) {
                    $errores['direccion'] = ['Cargá tu dirección antes de pedir la seña en las confirmaciones.'];
                }

                // §Decision #691: mismo gate de ubicación que los toggles
                // automáticos — sin lat/long no se puede pedir seña.
                if (is_null($valorFinal('latitud')) || is_null($valorFinal('longitud'))) {
                    $errores['latitud'] = ['Marcá tu ubicación en el mapa antes de pedir la seña en las confirmaciones.'];
                }

                if (blank($valorFinal('whatsapp_sena_titular'))) {
                    $errores['whatsapp_sena_titular'] = ['Cargá el titular de la cuenta para pedir la seña.'];
                }

                if (blank($valorFinal('whatsapp_sena_alias')) && blank($valorFinal('whatsapp_sena_cbu'))) {
                    $errores['whatsapp_sena_alias'] = ['Cargá el alias o el CBU de la cuenta para pedir la seña.'];
                }

                if ($errores !== []) {
                    throw ValidationException::withMessages($errores);
                }
            }
        }

        // Apagar "atiende en paralelo" con promos paralelas activas dejaría
        // su duración y su oferta cambiadas en silencio: se bloquea y se
        // devuelve la lista para que la dueña las pase a secuencia primero.
        // Solo cuenta la transición ON -> OFF (apagar algo ya apagado es
        // idempotente) y solo las promos de este negocio.
        if (array_key_exists('atiende_en_paralelo', $data) && ! $data['atiende_en_paralelo'] && $user->atiende_en_paralelo) {
            $promosParalelas = $user->servicios()
                ->where('es_promo', true)
                ->where('activo', true)
                ->where('modo_promo', 'paralelo')
                ->orderBy('id')
                ->get(['id', 'nombre']);

            if ($promosParalelas->isNotEmpty()) {
                return response()->json([
                    'message' => 'No podés apagar "Atiende en paralelo" mientras tengas promos en paralelo activas. Pasalas a secuencia primero.',
                    'code' => 'promos_paralelas_activas',
                    'promos' => $promosParalelas->map(fn ($promo) => ['id' => $promo->id, 'nombre' => $promo->nombre])->all(),
                ], 422);
            }
        }

        // La columna es NOT NULL default 0: null significa "sin retencion".
        if (array_key_exists('retencion_iibb_porcentaje', $data) && $data['retencion_iibb_porcentaje'] === null) {
            $data['retencion_iibb_porcentaje'] = 0;
        }

        // Modo de la seña mutuamente excluyente: se limpia el campo del modo
        // que no quedo activo (fijo -> sena_porcentaje null, porcentaje ->
        // sena_monto null). Solo si la request toco algun campo de la seña.
        if (array_intersect(['sena_tipo', 'sena_monto', 'sena_porcentaje'], array_keys($data))) {
            $tipoFinal = $data['sena_tipo'] ?? $user->sena_tipo;
            $data[$tipoFinal === 'porcentaje' ? 'sena_monto' : 'sena_porcentaje'] = null;
        }

        $user->update($data);

        return response()->json($user->append('comision_mp_vigente'));
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
        if (! $path) {
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
            'email' => 'required|email',
        ]);

        $email = strtolower($data['email']);

        // Misma respuesta exista o no el email: con un `exists` en la validacion
        // cualquiera podia averiguar que emails estan registrados.
        if (User::where('email', $email)->exists()) {
            $code = (string) random_int(100000, 999999);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                [
                    'token' => Hash::make($code),
                    'created_at' => now(),
                ],
            );
            Cache::forget(self::claveIntentosReset($email));

            Mail::to($email)->send(new ResetCodeMail($code));
        }

        return response()->json([
            'message' => 'Te enviamos un código a tu email.',
        ]);
    }

    private const MAX_INTENTOS_RESET = 5;

    private static function claveIntentosReset(string $email): string
    {
        return 'reset-password-intentos:'.sha1($email);
    }

    // ─────────────────────────────────────────────
    // POST /api/auth/reset-password
    // ─────────────────────────────────────────────
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'code' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $email = strtolower($data['email']);

        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        // Sin registro (email inexistente o codigo ya invalidado) se responde
        // igual que un codigo incorrecto, sin revelar si el email existe.
        if (! $record) {
            throw ValidationException::withMessages([
                'code' => ['El código ingresado es incorrecto.'],
            ]);
        }

        if (! Hash::check($data['code'], $record->token)) {
            // El codigo tiene 1M de valores posibles: tras 5 fallos se invalida
            // y hay que pedir uno nuevo, para que no se pueda adivinar por
            // fuerza bruta repartida entre muchas IPs.
            $clave = self::claveIntentosReset($email);
            $intentos = Cache::increment($clave);
            Cache::put($clave, $intentos, now()->addMinutes(30));
            if ($intentos >= self::MAX_INTENTOS_RESET) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
                Cache::forget($clave);
            }

            throw ValidationException::withMessages([
                'code' => ['El código ingresado es incorrecto.'],
            ]);
        }

        // Comparar instantes (no diffInMinutes): en Carbon 3 el diff tiene signo y un
        // created_at pasado daba negativo, asi que el codigo nunca expiraba.
        if (Carbon::parse($record->created_at)->lt(now()->subMinutes(30))) {
            throw ValidationException::withMessages([
                'code' => ['El código expiró. Solicitá uno nuevo.'],
            ]);
        }

        $user = User::where('email', $email)->first();
        $user->update([
            'password' => bcrypt($data['password']),
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
                'status' => 'ACTIVO',
                'is_exempt' => true,
                'ends_at' => null,
                'days_left' => null,
                'code' => null,
            ]);
        }

        $subscription = $user->subscription;

        if (! $subscription) {
            return response()->json([
                'status' => 'VENCIDO',
                'is_exempt' => false,
                'ends_at' => null,
                'days_left' => 0,
                'code' => 'NO_SUBSCRIPTION',
            ]);
        }

        $daysLeft = max(0, (int) now()->diffInDays($subscription->ends_at, false));

        // SUSPENDIDO es el único status guardado que no se deriva de ends_at:
        // se devuelve tal cual para que un dueño cortado vea el motivo real
        // (este endpoint NO pasa por CheckSubscription). `code` espeja el
        // mismo vocabulario que CheckSubscription y es puramente aditivo —
        // el frontend sigue gateando el bloqueo por `status !== 'ACTIVO'`.
        $suspendida = $subscription->status === 'SUSPENDIDO';
        $vencida = $subscription->ends_at <= now();

        return response()->json([
            'status' => $suspendida
                ? 'SUSPENDIDO'
                : ($subscription->ends_at > now() ? 'ACTIVO' : 'VENCIDO'),
            'is_exempt' => false,
            'ends_at' => $subscription->ends_at,
            'days_left' => $daysLeft,
            'code' => match (true) {
                $suspendida => 'SUBSCRIPTION_SUSPENDED',
                $vencida => 'SUBSCRIPTION_EXPIRED',
                default => null,
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
            'whatsapp' => Setting::get('support_whatsapp'),
            'email' => Setting::get('support_email'),
            'subscription_warning_days' => (int) Setting::get('subscription_warning_days'),
        ]);
    }
}

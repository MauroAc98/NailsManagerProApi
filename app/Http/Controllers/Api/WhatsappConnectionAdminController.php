<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PhoneNumberYaVinculadoException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsappConnection;
use App\Services\EmbeddedSignupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Superficie HTTP del onboarding de Embedded Signup (design §7).
 *
 * Controller propio a propósito — NO un método de AdminController, que ya está
 * en ~290 líneas cruzando cuatro concerns no relacionados.
 *
 * El seam reusable es EmbeddedSignupService::conectar(User $user, …), cuya firma
 * toma el User explícito en vez de leer el contexto de auth. La gate de Advanced
 * Access vive DENTRO de conectar() (via GuardEmbeddedSignup), no acá: si el
 * chequeo viviera sólo en este controller, un futuro POST /api/whatsapp/connection
 * bajo auth:sanctum que reusa conectar() saltearía la gate por completo. Este
 * controller sólo traduce excepciones a HTTP.
 */
class WhatsappConnectionAdminController extends Controller
{
    /**
     * GET /api/admin/whatsapp/connections
     *
     * INGATEADO — debe seguir alcanzable mientras la feature está gated (Q1).
     * Nunca llama a conectar(). Devuelve la config pública de ES que el
     * frontend necesita para el SDK de Facebook, más una fila por salón con su
     * estado de conexión (`sin_conexion` cuando no hay fila — design §Q4).
     */
    public function index(): JsonResponse
    {
        $salones = User::query()
            ->with('whatsappConnection')
            ->orderBy('name')
            ->get()
            ->map(fn (User $salon) => [
                'user_id' => $salon->id,
                'nombre' => $salon->name,
                'estado' => $salon->whatsappConnection?->estado ?? 'sin_conexion',
                'display_phone_number' => $salon->whatsappConnection?->display_phone_number,
                'verified_name' => $salon->whatsappConnection?->verified_name,
                'token_expires_at' => $salon->whatsappConnection?->token_expires_at,
            ])
            ->values();

        return response()->json([
            'es' => [
                'enabled' => (bool) config('services.whatsapp_es.enabled'),
                'app_id' => config('services.whatsapp_es.app_id'),
                'config_id' => config('services.whatsapp_es.config_id'),
                'graph_version' => config('services.whatsapp_es.graph_version'),
            ],
            'salones' => $salones,
        ]);
    }

    /**
     * POST /api/admin/whatsapp/connections
     *
     * Body `{ user_id, code, waba_id, phone_number_id? }`. Delega en el seam;
     * la gate (403) y las fallas de Meta (422/502) renderizan solas por ser
     * HttpException. Sólo se captura la colisión cross-salón tipada para armar
     * el 409 con el nombre del dueño — info sólo-admin (design §7, A8). El
     * `access_token` nunca sale en la respuesta: lo garantiza `$hidden` en el
     * modelo, no un strip manual.
     */
    public function store(Request $request): JsonResponse
    {
        // Guarda de tiempo de request, sólo para esta ruta. En prod, nginx usa
        // el `fastcgi_read_timeout` default de 60s y PHP-FPM tiene
        // `request_terminate_timeout` deshabilitado, pero el `php.ini` de FPM
        // fija `max_execution_time = 30`. El intercambio de Embedded Signup
        // tiene un presupuesto worst-case de ~39s (design §3: 8s + 3×5s + 2×8s
        // + reintentos), así que subimos el límite por-request acá.
        // (En Linux `max_execution_time` normalmente excluye la espera de cURL;
        // esto es belt-and-suspenders y acotado a esta ruta.)
        set_time_limit(70);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'code' => ['required', 'string'],
            'waba_id' => ['required', 'string'],
            'phone_number_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $salon = User::findOrFail($data['user_id']);

        try {
            $conexion = app(EmbeddedSignupService::class)->conectar(
                $salon,
                $data['code'],
                $data['waba_id'],
                $data['phone_number_id'] ?? null,
            );
        } catch (PhoneNumberYaVinculadoException $e) {
            $dueno = WhatsappConnection::where('phone_number_id', $e->phoneNumberId)->first()?->user;

            return response()->json([
                'message' => $dueno !== null
                    ? "Ese número de WhatsApp ya está conectado al salón «{$dueno->name}»."
                    : 'Ese número de WhatsApp ya está conectado a otro salón.',
                'phone_number_id' => $e->phoneNumberId,
                'salon_dueno' => $dueno !== null ? ['id' => $dueno->id, 'name' => $dueno->name] : null,
            ], 409);
        }

        return response()->json($conexion->append('estado'), 201);
    }
}

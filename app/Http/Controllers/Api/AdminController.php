<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ProvisionalPasswordMail;
use App\Models\Profesional;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Services\AdminAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    // La autorización de admin/* la resuelve el middleware auth:admin
    // (routes/api.php) — ver AdminUser / config/auth.php.
    public function renewSubscription(Request $request, User $user): JsonResponse
    {
        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json(['error' => 'El usuario no tiene suscripción'], 404);
        }

        $renewedRecently = $subscription->renewed_at && $subscription->renewed_at->gt(now()->subHours(24));

        if ($renewedRecently && !$request->boolean('force')) {
            return response()->json([
                'error' => 'Esta suscripción ya fue renovada hace menos de 24hs',
                'renewed_at' => $subscription->renewed_at,
                'ends_at' => $subscription->ends_at,
                'hint' => 'Si es intencional, repetí el request con ?force=true',
            ], 409);
        }

        $subscription->update([
            'ends_at' => now()->max($subscription->ends_at)->copy()->addDays(30),
            'status'  => 'ACTIVO',
            'renewed_at' => now(),
        ]);

        AdminAudit::record($request->user('admin'), 'suscripcion.renovada', $user->id, [
            'ends_at' => $subscription->fresh()->ends_at,
        ], $request);

        return response()->json([
            'message' => 'Suscripción renovada correctamente',
            'user_id' => $user->id,
            'ends_at' => $subscription->fresh()->ends_at,
        ]);
    }

    /**
     * POST /api/admin/negocios
     * Reemplaza a AuthController::register() — la creación de negocios se
     * muda acá porque ahora exige sesión admin explícita (auth:admin) en
     * vez de la ruta pública auth/register + X-Admin-Secret. Misma
     * semántica de creación (contraseña provisoria + email), con auditoría.
     */
    public function crearNegocio(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower((string) $request->input('email'))]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'profesional_nombre' => 'required|string|max:255',
            'profesional_apellido' => 'required|string|max:255',
        ]);

        $passwordProvisoria = Str::password(10, symbols: false);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $passwordProvisoria,
            'debe_cambiar_password' => true,
        ]);

        Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(10),
            'status' => 'ACTIVO',
        ]);

        // Ver AuthController (register original, movido acá) sobre por qué
        // 'nombre' del profesional no puede ser una copia de 'name' del
        // negocio.
        Profesional::create([
            'user_id' => $user->id,
            'nombre' => $data['profesional_nombre'],
            'apellido' => $data['profesional_apellido'],
            'activo' => true,
        ]);

        Mail::to($user->email)->send(
            new ProvisionalPasswordMail($user->name, $user->email, $passwordProvisoria),
        );

        AdminAudit::record($request->user('admin'), 'negocio.creado', $user->id, [
            'name' => $user->name,
            'slug' => $user->slug,
        ], $request);

        return response()->json([
            'message' => 'Negocio creado. Se envió la contraseña provisoria por email.',
            'user' => $user,
            'password_provisoria' => $passwordProvisoria,
        ], 201);
    }

    /**
     * GET /api/admin/negocios
     * Listado completo (no paginado — solo 6 negocios existen en prod hoy,
     * no vale la pena LengthAwarePaginator para v1). Orden por ends_at
     * ascendente: el workflow real del admin es ver primero quién vence
     * antes para priorizar la renovación.
     */
    public function listarNegocios(): JsonResponse
    {
        $negocios = User::with('subscription')
            ->leftJoin('subscriptions', 'subscriptions.user_id', '=', 'users.id')
            ->orderBy('subscriptions.ends_at')
            ->select('users.*')
            ->get();

        return response()->json($negocios->map(fn (User $negocio) => [
            'id' => $negocio->id,
            'name' => $negocio->name,
            'slug' => $negocio->slug,
            'email' => $negocio->email,
            'is_exempt' => $negocio->is_exempt,
            'subscription' => $negocio->subscription ? [
                'ends_at' => $negocio->subscription->ends_at,
                // Status computado en vivo, no la columna guardada — ver
                // AuthController::subscriptionStatus() (mismo criterio).
                // La columna 'status' nunca se actualiza sola cuando
                // ends_at simplemente pasa (no hay job programado).
                'status' => $negocio->subscription->ends_at > now() ? 'ACTIVO' : 'VENCIDO',
                'renewed_at' => $negocio->subscription->renewed_at,
            ] : null,
        ])->values());
    }

    /**
     * GET /api/admin/negocios/buscar?q=
     * Búsqueda puntual (no listado paginable, ver spec admin-subscription-
     * renewal): email exacto o slug parcial (LIKE), máximo 5 resultados.
     */
    public function buscarNegocio(Request $request): JsonResponse
    {
        $q = strtolower(trim((string) $request->query('q', '')));

        if ($q === '') {
            return response()->json([]);
        }

        $negocios = User::where(function ($query) use ($q) {
            $query->where('email', $q)
                ->orWhere('slug', 'like', "%{$q}%");
        })
            ->with('subscription')
            ->limit(5)
            ->get();

        return response()->json($negocios->map(fn (User $negocio) => [
            'id' => $negocio->id,
            'name' => $negocio->name,
            'slug' => $negocio->slug,
            'email' => $negocio->email,
            'is_exempt' => $negocio->is_exempt,
            'subscription' => $negocio->subscription ? [
                'ends_at' => $negocio->subscription->ends_at,
                // Status computado en vivo, no la columna guardada — ver
                // AuthController::subscriptionStatus() (mismo criterio).
                // La columna 'status' nunca se actualiza sola cuando
                // ends_at simplemente pasa (no hay job programado).
                'status' => $negocio->subscription->ends_at > now() ? 'ACTIVO' : 'VENCIDO',
                'renewed_at' => $negocio->subscription->renewed_at,
            ] : null,
        ])->values());
    }

    /**
     * GET /api/admin/whatsapp/uso-por-salon?desde=&hasta=
     * Uso de WhatsApp Cloud API por salón (Evolution es gratis, no se
     * cuenta) — mensajes crudos y una aproximación de conversaciones de
     * 24hs (Meta cobra por conversación, no por mensaje individual), para
     * poder cotejar cuánto le está costando cada profesional.
     */
    public function usoWhatsappPorSalon(Request $request): JsonResponse
    {
        $desde = $request->filled('desde')
            ? Carbon::parse($request->query('desde'))->startOfDay()
            : now()->startOfMonth();
        $hasta = $request->filled('hasta')
            ? Carbon::parse($request->query('hasta'))->endOfDay()
            : now()->endOfDay();

        $mensajes = WhatsappMensaje::where('provider', 'cloud_api')
            ->whereBetween('created_at', [$desde, $hasta])
            ->orderBy('created_at')
            ->get(['user_id', 'numero', 'created_at']);

        $porUsuario = $mensajes->groupBy('user_id');

        $nombres = User::whereIn('id', $porUsuario->keys())->pluck('name', 'id');

        $salones = $porUsuario->map(function ($mensajesDelUsuario, $userId) use ($nombres) {
            $conversaciones = 0;

            foreach ($mensajesDelUsuario->groupBy('numero') as $mensajesDelNumero) {
                $inicioVentana = null;

                foreach ($mensajesDelNumero as $mensaje) {
                    // diffInHours() en Carbon 3 devuelve el valor CON SIGNO por
                    // default (a diferencia de Carbon 2) — sin absolute:true acá
                    // daba negativo y la comparación ">= 24" nunca abría una
                    // conversación nueva.
                    if ($inicioVentana === null || $mensaje->created_at->diffInHours($inicioVentana, absolute: true) >= 24) {
                        $conversaciones++;
                        $inicioVentana = $mensaje->created_at;
                    }
                }
            }

            return [
                'user_id' => (int) $userId,
                'nombre' => $nombres[$userId] ?? null,
                'mensajes_totales' => $mensajesDelUsuario->count(),
                'conversaciones_estimadas' => $conversaciones,
            ];
        })
            ->sortByDesc('conversaciones_estimadas')
            ->values();

        return response()->json([
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'salones' => $salones,
        ]);
    }
}

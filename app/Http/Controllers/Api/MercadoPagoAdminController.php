<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserMpCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fase 1: cada negocio tiene su PROPIA cuenta de Mercado Pago, cargada a
 * mano por el equipo de Turnetto (sin OAuth propio, a diferencia de
 * WhatsApp Embedded Signup — ver WhatsappConnectionAdminController, que se
 * deja intacto). Reemplaza cargar UserMpCredential por `artisan tinker` cada
 * vez por un form simple en el panel admin.
 *
 * El access_token NUNCA sale en la respuesta: lo garantiza `$hidden` en el
 * modelo (UserMpCredential::$hidden), no un strip manual acá.
 */
class MercadoPagoAdminController extends Controller
{
    // GET /api/admin/mercadopago/connections
    public function index(): JsonResponse
    {
        $salones = User::query()
            ->with('mpCredentials')
            ->orderBy('name')
            ->get()
            ->map(fn (User $salon) => [
                'user_id' => $salon->id,
                'nombre' => $salon->name,
                'slug' => $salon->slug,
                'sena_monto' => $salon->sena_monto,
                'conectado' => $salon->mpCredentials !== null,
                'mp_user_id' => $salon->mpCredentials?->mp_user_id,
                // Se arma con el ruteo YA guardado (autogenerado al crear la
                // credencial) — la URL nunca cambia al actualizar el token,
                // asi que lo pegado en el panel de MP sigue siendo valido.
                'webhook_url' => $salon->mpCredentials !== null
                    ? url("/api/webhooks/mercadopago/{$salon->mpCredentials->webhook_ruteo}")
                    : null,
            ])
            ->values();

        return response()->json(['salones' => $salones]);
    }

    // POST /api/admin/mercadopago/connections
    // Body `{ user_id, mp_access_token, mp_user_id }`. updateOrCreate: cargar
    // de nuevo sobre un negocio ya conectado ROTA el token sin tocar el
    // webhook_ruteo existente (creating() solo corre al insertar la fila).
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'mp_access_token' => ['required', 'string'],
            'mp_user_id' => ['required', 'string', 'max:100'],
        ]);

        $credencial = UserMpCredential::updateOrCreate(
            ['user_id' => $data['user_id']],
            ['mp_access_token' => $data['mp_access_token'], 'mp_user_id' => $data['mp_user_id']],
        );

        return response()->json([
            'user_id' => $credencial->user_id,
            'mp_user_id' => $credencial->mp_user_id,
            'webhook_url' => url("/api/webhooks/mercadopago/{$credencial->webhook_ruteo}"),
        ], 201);
    }
}

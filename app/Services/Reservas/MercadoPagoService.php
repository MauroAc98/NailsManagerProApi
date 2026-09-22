<?php

namespace App\Services\Reservas;

use App\Exceptions\ReservaPublicaException;
use App\Models\PagoSena;
use App\Models\ReservaWeb;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Crea la preferencia de Checkout Pro para cobrar la seña. Fase 1: cada
 * negocio usa SU PROPIA cuenta de Mercado Pago (sin OAuth, sin app de
 * Turnetto) — el access_token sale de user_mp_credentials, cargado a mano.
 * La plata cae directo en la cuenta del negocio; Turnetto nunca la toca.
 */
class MercadoPagoService
{
    /**
     * Reusa la preferencia pendiente de esta reserva si ya existe (un
     * reintento de /pago no debe crear una preferencia nueva en MP cada vez).
     *
     * @throws ReservaPublicaException mp_no_conectado | mp_error
     */
    public function crearOReusarPreferencia(User $user, ReservaWeb $reserva): PagoSena
    {
        // Lock de la fila de la reserva: sin esto, dos requests casi
        // simultaneas (doble tap de "Pagar") pasaban juntas el chequeo de
        // abajo y creaban DOS preferencias en MP para la misma reserva —
        // despues era ambiguo a cual de las dos correspondia un pago que
        // llegaba por el webhook. Misma tecnica que SlotLock para holds/turnos,
        // aca sobre la fila puntual en vez de un advisory lock por profesional.
        return DB::transaction(function () use ($user, $reserva) {
            ReservaWeb::whereKey($reserva->id)->lockForUpdate()->first();

            return $this->crearOReusarPreferenciaBloqueada($user, $reserva);
        });
    }

    private function crearOReusarPreferenciaBloqueada(User $user, ReservaWeb $reserva): PagoSena
    {
        $existente = PagoSena::where('reserva_web_id', $reserva->id)
            ->where('estado', 'pendiente')
            ->whereNotNull('init_point')
            ->latest('id')
            ->first();
        if ($existente !== null) {
            return $existente;
        }

        $credencial = $user->mpCredentials;
        $monto = round((float) ($user->sena_monto ?? 0), 2);
        if ($credencial === null || $monto <= 0) {
            throw ReservaPublicaException::mpNoConectado();
        }

        $base = rtrim((string) config('services.frontend_url'), '/');
        $volver = "{$base}/reservar/{$user->slug}/reserva/{$reserva->public_token}";

        $response = Http::withToken($credencial->mp_access_token)
            ->timeout(15)
            ->post('https://api.mercadopago.com/checkout/preferences', [
                'items' => [[
                    'title' => "Seña de reserva - {$user->name}",
                    'quantity' => 1,
                    'currency_id' => $user->locale === 'pt-BR' ? 'BRL' : 'ARS',
                    'unit_price' => $monto,
                ]],
                // El token publico, NUNCA el id interno de la reserva — mismo
                // criterio que las URLs de este flujo (ver ReservaPublicaController).
                'external_reference' => $reserva->public_token,
                // Rapipago/Pago Facil tardan HASTA DIAS en acreditarse — con una
                // ventana de pago de 15 minutos, la clienta pagaria igual y de
                // todos modos perderia el horario. Se excluyen a proposito.
                'payment_methods' => ['excluded_payment_types' => [['id' => 'ticket']]],
                'back_urls' => ['success' => $volver, 'pending' => $volver, 'failure' => $volver],
                'auto_return' => 'approved',
                // Ruteo propio del negocio: ver comentario en la migracion
                // add_webhook_ruteo_to_user_mp_credentials_table.
                'notification_url' => rtrim((string) config('app.url'), '/')."/api/webhooks/mercadopago/{$credencial->webhook_ruteo}",
            ]);

        if (! $response->successful()) {
            Log::error('mercadopago.preferencia.fallo', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw ReservaPublicaException::mpError();
        }

        $data = $response->json() ?? [];

        return PagoSena::create([
            'reserva_web_id' => $reserva->id,
            'mp_preference_id' => $data['id'] ?? null,
            'init_point' => $data['init_point'] ?? null,
            'monto' => $monto,
            'estado' => 'pendiente',
        ]);
    }
}

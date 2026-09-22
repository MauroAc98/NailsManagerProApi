<?php

namespace App\Services\Reservas;

use App\Exceptions\ReservaPublicaException;
use App\Models\PagoSena;
use App\Models\ReservaWeb;
use App\Models\User;
use App\Models\UserMpCredential;
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
    public function __construct(private ConfirmarReservaService $confirmar) {}

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

    /**
     * Trae el estado real de un pago desde MP — nunca el que vino en el
     * cuerpo del webhook, que MP mismo advierte que puede llegar incompleto
     * o desactualizado. Falla fuerte (para que MP reintente la notificacion).
     *
     * @return array<string, mixed>
     *
     * @throws ReservaPublicaException mp_error
     */
    public function consultarPago(UserMpCredential $credencial, string $paymentId): array
    {
        $response = Http::withToken($credencial->mp_access_token)
            ->timeout(15)
            ->get("https://api.mercadopago.com/v1/payments/{$paymentId}");

        if (! $response->successful()) {
            Log::error('mercadopago.consultar_pago.fallo', [
                'user_id' => $credencial->user_id,
                'payment_id' => $paymentId,
                'status' => $response->status(),
            ]);

            throw ReservaPublicaException::mpError();
        }

        return $response->json() ?? [];
    }

    /**
     * Busca un pago por external_reference (el public_token de la reserva)
     * cuando no llego NINGUN aviso de MP — a diferencia de consultarPago, no
     * hay un payment_id del que partir. Usada por la reconciliacion, que
     * recorre muchas filas: una falla puntual de MP no debe tumbar el lote
     * entero, por eso devuelve null en vez de lanzar.
     *
     * @return array<string, mixed>|null
     */
    public function buscarPagoPorExternalReference(UserMpCredential $credencial, string $externalReference): ?array
    {
        $response = Http::withToken($credencial->mp_access_token)
            ->timeout(15)
            ->get('https://api.mercadopago.com/v1/payments/search', [
                'external_reference' => $externalReference,
            ]);

        if (! $response->successful()) {
            Log::error('mercadopago.buscar_pago.fallo', [
                'user_id' => $credencial->user_id,
                'external_reference' => $externalReference,
                'status' => $response->status(),
            ]);

            return null;
        }

        return $response->json()['results'][0] ?? null;
    }

    /**
     * Aplica el estado de un pago de MP a su PagoSena/ReservaWeb — el UNICO
     * lugar que decide que hacer con un pago, compartido entre el webhook
     * (avisos que llegan solos) y la reconciliacion (re-consulta manual), para
     * que las dos vias nunca queden con logica distinta.
     *
     * @param  array<string, mixed>  $datosPago  lo que devuelve consultarPago/buscarPagoPorExternalReference
     */
    public function sincronizarPago(PagoSena $pagoSena, ReservaWeb $reserva, array $datosPago): void
    {
        if ($pagoSena->estaAprobado()) {
            return;
        }

        $status = (string) ($datosPago['status'] ?? '');
        $paymentId = (string) ($datosPago['id'] ?? '');

        $estado = match ($status) {
            'approved' => 'aprobado',
            'rejected', 'cancelled' => 'rechazado',
            default => 'pendiente',
        };

        // Defensa QA: nunca deberia pasar, pero si el monto que MP dice haber
        // cobrado no coincide con lo pedido, no confirmamos a ciegas.
        if ($estado === 'aprobado') {
            $montoPagado = round((float) ($datosPago['transaction_amount'] ?? 0), 2);
            $montoEsperado = round((float) $pagoSena->monto, 2);
            if (abs($montoPagado - $montoEsperado) > 0.01) {
                Log::error('mercadopago.sincronizar.monto_no_coincide', [
                    'pago_sena_id' => $pagoSena->id,
                    'payment_id' => $paymentId,
                    'monto_esperado' => $montoEsperado,
                    'monto_pagado' => $montoPagado,
                ]);

                return;
            }
        }

        $pagoSena->update([
            'mp_payment_id' => $paymentId,
            'estado' => $estado,
            // Diagnostico de reclamos ("pagué y no me confirmó"): sin esto,
            // averiguar por que un pago se rechazo requeria ir a buscarlo a
            // mano al dashboard de MP con el payment_id.
            'status_detail' => $datosPago['status_detail'] ?? null,
            'payment_method_id' => $datosPago['payment_method_id'] ?? null,
            'payment_type_id' => $datosPago['payment_type_id'] ?? null,
        ]);

        if ($estado !== 'aprobado') {
            return;
        }

        $resultado = $this->confirmar->confirmar($reserva, now());

        if ($resultado->resultado === ConfirmacionResultado::NEEDS_REFUND) {
            Log::error('mercadopago.sincronizar.pago_aprobado_requiere_reembolso', [
                'reserva_id' => $reserva->id,
                'payment_id' => $paymentId,
                'motivo' => $resultado->motivo,
            ]);
        }
    }
}

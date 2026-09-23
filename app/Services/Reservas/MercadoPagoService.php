<?php

namespace App\Services\Reservas;

use App\Exceptions\ReservaPublicaException;
use App\Models\PagoSena;
use App\Models\ReservaWeb;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserMpCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Crea la order de Checkout Pro (Orders API) para cobrar la seña. Fase 1:
 * cada negocio usa SU PROPIA cuenta de Mercado Pago (sin OAuth, sin app de
 * Turnetto) — el access_token sale de user_mp_credentials, cargado a mano.
 * La plata cae directo en la cuenta del negocio; Turnetto nunca la toca.
 *
 * Migrado desde la API vieja de Preferences (checkout/preferences) a Orders
 * API (v1/orders) — MP la recomienda para integraciones nuevas. El webhook y
 * la reconciliacion NO cambian: ambas APIs notifican type=payment y la fuente
 * de verdad sigue siendo GET /v1/payments/{id}, nunca el cuerpo del aviso.
 */
class MercadoPagoService
{
    public function __construct(private ConfirmarReservaService $confirmar) {}

    /**
     * Reusa la order pendiente de esta reserva si ya existe (un reintento de
     * /pago no debe crear una order nueva en MP cada vez).
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
        $senaMonto = round((float) ($user->sena_monto ?? 0), 2);
        if ($credencial === null || $senaMonto <= 0) {
            throw ReservaPublicaException::mpNoConectado();
        }

        // Se cobra de mas para que, despues de la comision de MP, el negocio
        // reciba el monto de seña completo — ver montoACobrar().
        $monto = $this->montoACobrar($senaMonto);

        // reservas.base_url, NUNCA services.frontend_url (ese apunta a
        // app.turnetto.com, el dashboard) — ver comentario en config/reservas.php.
        $base = rtrim((string) config('reservas.base_url'), '/');
        $volver = "{$base}/reservar/{$user->slug}/reserva/{$reserva->public_token}";
        $montoFormateado = number_format($monto, 2, '.', '');

        $response = Http::withToken($credencial->mp_access_token)
            // Idempotency-Key estable por reserva: si esta llamada se
            // reintentara alguna vez (timeout de red), MP devuelve la MISMA
            // order en vez de crear una duplicada. Solo se llega aca una vez
            // por reserva — el chequeo de arriba ya reusa la fila local en
            // cualquier reintento normal (doble tap de "Pagar").
            ->withHeaders(['X-Idempotency-Key' => "reserva-{$reserva->public_token}"])
            ->timeout(15)
            ->post('https://api.mercadopago.com/v1/orders', [
                'type' => 'online',
                // Unico valor valido para Checkout Pro (redirect hospedado) —
                // "automatic" es para integraciones de Checkout API a medida.
                'processing_mode' => 'manual',
                'total_amount' => $montoFormateado,
                'items' => [[
                    'title' => "Seña de reserva - {$user->name}",
                    'quantity' => 1,
                    'unit_price' => $montoFormateado,
                ]],
                // El token publico, NUNCA el id interno de la reserva — mismo
                // criterio que las URLs de este flujo (ver ReservaPublicaController).
                'external_reference' => $reserva->public_token,
                'config' => [
                    'online' => [
                        'success_url' => $volver,
                        'pending_url' => $volver,
                        'failure_url' => $volver,
                        'auto_return' => 'approved',
                    ],
                    'payment_method' => [
                        // Rapipago/Pago Facil tardan HASTA DIAS en acreditarse
                        // — con una ventana de pago de 15 minutos, la clienta
                        // pagaria igual y de todos modos perderia el horario.
                        'not_allowed_types' => ['ticket'],
                    ],
                ],
                // notification_url NO va aca: Orders API la configura UNA VEZ
                // por aplicacion de MP (panel developers, "Modo productivo"),
                // no por request. El ruteo propio del negocio
                // (UserMpCredential::webhook_ruteo) sigue funcionando igual —
                // solo cambia DONDE se configura esa URL, no como identifica
                // al negocio.
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
            // Sigue en la columna 'mp_preference_id' por no forzar una
            // migracion de renombre sin beneficio funcional — desde esta
            // migracion a Orders API, guarda el id de la ORDER, no de una
            // preference.
            'mp_preference_id' => $data['id'] ?? null,
            'init_point' => $data['checkout_url'] ?? null,
            'monto' => $monto,
            'estado' => 'pendiente',
        ]);
    }

    // Comision de Mercado Pago por cobro "al instante" (Setting global,
    // panel admin > Configuracion) — el negocio la ve en su propia cuenta de
    // MP bajo "Dinero disponible en". Constante para todos los negocios por
    // ahora (fase 1): si algun negocio tuviera una tasa negociada distinta,
    // pasa a ser por-negocio mas adelante.
    // Publica: AdminController::obtenerSettings la usa como default a
    // mostrar cuando todavia no se guardo un valor explicito. Es la comision
    // TAL CUAL la muestra el panel de MP ("Dinero disponible en") — SIN IVA,
    // el admin la copia directo de ahi sin hacer ninguna cuenta.
    public const COMISION_MP_DEFAULT = 6.29;

    // El cargo real que MP descuenta incluye 21% de IVA sobre su comision —
    // confirmado contra un cobro real: comision nominal 6,29%, cargo
    // efectivo 7,59% (6,29 * 1,21). Se aplica siempre aca, no se le pide al
    // admin que lo sume a mano al cargar el %.
    private const IVA_PORCENTAJE = 21;

    /**
     * Monto a cobrarle a la clienta para que, descontada la comision de MP
     * (con IVA incluido), el negocio reciba exactamente `$senaMonto` neto —
     * sin esto, el negocio terminaba recibiendo la seña menos la comision sin
     * haberlo decidido. Formula: cobro = neto / (1 - comision_con_iva). 0 se
     * mantiene en 0 (sin seña configurada, no hay nada que cobrar de mas).
     */
    public function montoACobrar(float $senaMonto): float
    {
        if ($senaMonto <= 0) {
            return 0.0;
        }

        $comisionNominal = (float) (Setting::get('comision_mp_porcentaje') ?? self::COMISION_MP_DEFAULT);
        $comisionConIva = $comisionNominal * (1 + self::IVA_PORCENTAJE / 100);

        return round($senaMonto / (1 - $comisionConIva / 100), 2);
    }

    /**
     * GET /users/me: identifica a que cuenta de MP pertenece un access_token.
     * Usada por el admin al cargar la credencial de un negocio (fase 1, carga
     * manual) para derivar mp_user_id solo, sin que alguien tenga que pegarlo
     * a mano — y de paso valida el token en el momento de guardarlo. Devuelve
     * null en vez de lanzar: quien llama decide el shape del error HTTP (esto
     * no es el flujo publico de reserva online, no aplica ReservaPublicaException).
     *
     * @return array<string, mixed>|null
     */
    public function obtenerCuenta(string $accessToken): ?array
    {
        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->get('https://api.mercadopago.com/users/me');

        if (! $response->successful()) {
            Log::warning('mercadopago.obtener_cuenta.fallo', ['status' => $response->status()]);

            return null;
        }

        return $response->json();
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

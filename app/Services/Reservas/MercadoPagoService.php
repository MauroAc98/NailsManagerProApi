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
        if ($credencial === null || ! $user->senaConfigCompleta()) {
            throw ReservaPublicaException::mpNoConectado();
        }

        // Se cobra la seña BRUTA (cubre la comision de MP y la retencion, ver
        // senaParaPrecio). Nunca se cobra 0: porcentaje sin precio conocido (o
        // con seña que da 0) -> sena_sin_total.
        $monto = $this->senaDeReserva($user, $reserva);
        if ($monto <= 0) {
            throw ReservaPublicaException::senaSinTotal();
        }
        $this->congelarPrecioTotal($reserva);

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

    /**
     * Seña NETA: lo que el profesional quiere recibir. Fijo: min(sena_monto,
     * precio). Porcentaje: round(precio * pct / 100) en pesos enteros, tope el
     * precio. 0 si la config es invalida o el precio es <= 0. `null` = precio
     * desconocido (p. ej. /terminos): el fijo va sin tope y el porcentaje da 0.
     */
    public function senaNetaParaPrecio(User $user, int|float|null $precio): float
    {
        if (! $user->senaConfigCompleta()) {
            return 0.0;
        }
        if ($precio !== null && $precio <= 0) {
            return 0.0;
        }

        if ($user->senaEsPorcentaje()) {
            $pct = (float) $user->sena_porcentaje;
            if ($precio === null || $pct > 100) {
                return 0.0;
            }

            return min((float) round($precio * $pct / 100), (float) $precio);
        }

        $monto = round((float) $user->sena_monto, 2);

        return $precio === null ? $monto : min($monto, (float) $precio);
    }

    /**
     * Seña que PAGA el cliente (y que se le manda a MP): el neto mas la
     * comision de MP con IVA y la retencion de IIBB, redondeado hacia arriba al
     * proximo multiplo de $100 y topeado al precio (si el tope aplica, el
     * profesional absorbe el resto). 0 si el neto es 0. `null` = precio
     * desconocido: sin tope.
     */
    public function senaParaPrecio(User $user, int|float|null $precio): float
    {
        $neta = $this->senaNetaParaPrecio($user, $precio);
        if ($neta <= 0) {
            return 0.0;
        }

        $tasa = min(
            $this->comisionVigente() + max(0.0, (float) ($user->retencion_iibb_porcentaje ?? 0)),
            self::TASA_TOTAL_MAXIMA,
        );
        $bruto = $neta / (1 - $tasa / 100);

        // Centavos enteros: primero a centavos (absorbe ruido de float como
        // 5600.00000001) y recien despues el ceil al multiplo.
        $centavos = (int) round($bruto * 100);
        $multiplo = self::REDONDEO_SENA_MULTIPLO * 100;
        $cobrada = (float) ((int) ceil($centavos / $multiplo) * self::REDONDEO_SENA_MULTIPLO);

        return $precio === null ? $cobrada : min($cobrada, (float) $precio);
    }

    /**
     * Precio total de la reserva: el snapshot (reservas_web.precio_total, tomado
     * al crear el hold) o, para reservas sin snapshot, el calculo de
     * TotalReserva. null = desconocido (total 0).
     */
    public function precioTotalDe(ReservaWeb $reserva): ?int
    {
        if ($reserva->precio_total !== null) {
            return (int) $reserva->precio_total;
        }

        $total = (int) round((new TotalReserva())->de($reserva));

        return $total > 0 ? $total : null;
    }

    public function senaDeReserva(User $user, ReservaWeb $reserva): float
    {
        return $this->senaParaPrecio($user, $this->precioTotalDe($reserva));
    }

    private function congelarPrecioTotal(ReservaWeb $reserva): void
    {
        if ($reserva->precio_total === null && ($precio = $this->precioTotalDe($reserva)) !== null) {
            $reserva->forceFill(['precio_total' => $precio])->save();
        }
    }

    /**
     * Seña que se le muestra al cliente para una reserva concreta: el monto
     * ya cobrado (PagoSena, congelado en el primer /pago) si existe; si no, el
     * calculo con la config actual. null = porcentaje sin total determinable.
     */
    public function depositoParaReserva(User $user, ReservaWeb $reserva): ?float
    {
        $pago = PagoSena::where('reserva_web_id', $reserva->id)->latest('id')->first();
        if ($pago !== null) {
            return (float) $pago->monto;
        }

        $sena = $this->senaDeReserva($user, $reserva);
        if ($sena <= 0 && $user->senaEsPorcentaje()) {
            return null;
        }

        return $sena;
    }

    // Comision de Mercado Pago por cobro "al instante" (Setting global,
    // panel admin > Configuracion) — el negocio la ve en su propia cuenta de
    // MP bajo "Dinero disponible en". Unica fuente: ya no hay comision por
    // negocio. La absorbe el profesional (el cliente paga solo la seña).
    // Publica: AdminController::obtenerSettings la usa como default a
    // mostrar cuando todavia no se guardo un valor explicito. Es la comision
    // TAL CUAL la muestra el panel de MP ("Dinero disponible en") — SIN IVA,
    // el admin la copia directo de ahi sin hacer ninguna cuenta.
    public const COMISION_MP_DEFAULT = 6.29;

    // Techo de la tasa total (comision + retencion) para el gross-up: evita
    // dividir por ~0 con una config absurda.
    private const TASA_TOTAL_MAXIMA = 95;

    // La seña cobrada se redondea hacia arriba a multiplos de este monto.
    private const REDONDEO_SENA_MULTIPLO = 100;

    // El cargo real que MP descuenta incluye 21% de IVA sobre su comision —
    // confirmado contra un cobro real: comision nominal 6,29%, cargo
    // efectivo 7,59% (6,29 * 1,21).
    private const IVA_PORCENTAJE = 21;

    /** Comision efectiva de MP (con IVA), en %: Setting global o la constante, por 1,21. */
    public function comisionVigente(): float
    {
        $nominal = (float) (Setting::get('comision_mp_porcentaje') ?? self::COMISION_MP_DEFAULT);

        return $nominal * (1 + self::IVA_PORCENTAJE / 100);
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

        // Atomico: marcar el pago 'aprobado' y confirmar la reserva van juntos.
        // Si confirmar() lanza (lock timeout, deadlock, findOrFail), el rollback
        // deja el pago como estaba ('pendiente') y la excepcion sube: el webhook
        // responde 5xx (MP reintenta) y el reconciliador lo vuelve a tomar. Sin
        // esto quedaba 'aprobado' + reserva sin confirmar y nada lo reintentaba.
        // NEEDS_REFUND no lanza: commitea requiere_reembolso junto al pago. Los
        // jobs de confirmar() usan DB::afterCommit, asi que salen recien al commit.
        $resultado = DB::transaction(function () use ($pagoSena, $reserva, $estado, $paymentId, $datosPago) {
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

            return $estado === 'aprobado' ? $this->confirmar->confirmar($reserva, now()) : null;
        });

        if ($resultado?->resultado === ConfirmacionResultado::NEEDS_REFUND) {
            Log::error('mercadopago.sincronizar.pago_aprobado_requiere_reembolso', [
                'reserva_id' => $reserva->id,
                'payment_id' => $paymentId,
                'motivo' => $resultado->motivo,
            ]);
        }
    }
}

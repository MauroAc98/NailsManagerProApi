<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ReservaPublicaException;
use App\Http\Controllers\Controller;
use App\Models\PagoSena;
use App\Models\ReservaWeb;
use App\Models\UserMpCredential;
use App\Services\Reservas\MercadoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de Mercado Pago, fase 1: cada negocio tiene SU PROPIA cuenta de MP
 * (su propia app, su propia clave de firma) — un unico secreto compartido no
 * sirve para verificar a todos, asi que el segmento opaco {ruteo} de la URL
 * (ver UserMpCredential::webhook_ruteo) identifica el negocio.
 *
 * Nunca se confia en el CUERPO de la notificacion (Mercado Pago mismo lo
 * advierte: puede llegar incompleto o desactualizado): siempre se vuelve a
 * consultar el pago real a la API de MP con el access_token de ESE negocio.
 * Esa consulta —no la notificacion— es la fuente de verdad.
 */
class MercadoPagoWebhookController extends Controller
{
    public function __construct(private MercadoPagoService $mercadoPago) {}

    // POST /api/webhooks/mercadopago/{ruteo}
    public function handle(Request $request, string $ruteo): JsonResponse
    {
        $credencial = UserMpCredential::where('webhook_ruteo', $ruteo)->first();
        if ($credencial === null) {
            abort(404);
        }

        $tipo = $request->input('type') ?? $request->input('topic');

        // Bug real en produccion: desde la migracion a Orders API, MP manda
        // type=order (no type=payment) — el filtro viejo descartaba TODAS las
        // notificaciones reales sin procesarlas, y cada pago terminaba
        // dependiendo del reconciliador (1-2 min de demora) en vez del
        // webhook instantaneo.
        if ($tipo === 'order') {
            return $this->handleOrden($request, $credencial);
        }

        // Solo nos interesan notificaciones de pago; todo lo demas se
        // reconoce sin procesar (nunca 500, MP no debe reintentar por esto).
        if ($tipo !== null && $tipo !== 'payment') {
            return response()->json(['ok' => true]);
        }

        $paymentId = $request->input('data.id') ?? $request->query('data.id');
        if (! $paymentId) {
            return response()->json(['ok' => true]);
        }

        try {
            $pago = $this->mercadoPago->consultarPago($credencial, (string) $paymentId);
        } catch (ReservaPublicaException) {
            // 500 a proposito (no el 502 que usa la excepcion en otros
            // contextos): que Mercado Pago reintente la notificacion.
            abort(500, 'No se pudo consultar el pago.');
        }

        $reserva = ReservaWeb::where('public_token', $pago['external_reference'] ?? null)
            ->where('user_id', $credencial->user_id)
            ->first();

        if ($reserva === null) {
            Log::warning('mercadopago.webhook.reserva_no_encontrada', [
                'user_id' => $credencial->user_id,
                'payment_id' => $paymentId,
            ]);

            return response()->json(['ok' => true]);
        }

        $pagoSena = PagoSena::where('reserva_web_id', $reserva->id)->latest('id')->first();
        if ($pagoSena === null) {
            Log::warning('mercadopago.webhook.pago_sena_no_encontrado', ['reserva_id' => $reserva->id]);

            return response()->json(['ok' => true]);
        }

        $this->mercadoPago->sincronizarPago($pagoSena, $reserva, $pago);

        return response()->json(['ok' => true]);
    }

    // data.id de una notificacion type=order es el ID DE LA ORDEN, no de un
    // pago — no hay que confundirlo con el paymentId del camino 'payment' de
    // arriba. El shape de data.transactions.payments de la orden no esta
    // confirmado contra un pago real nuestro todavia, asi que en vez de
    // parsearlo se reusa buscarPagoPorExternalReference() (Payments API
    // clasica, /v1/payments/search): la MISMA consulta que ya usa la
    // reconciliacion y que confirmo un pago real exitosamente.
    private function handleOrden(Request $request, UserMpCredential $credencial): JsonResponse
    {
        $orderId = $request->input('data.id');
        if (! $orderId) {
            return response()->json(['ok' => true]);
        }

        $pagoSena = PagoSena::where('mp_preference_id', $orderId)
            ->whereHas('reservaWeb', fn ($q) => $q->where('user_id', $credencial->user_id))
            ->latest('id')
            ->first();

        if ($pagoSena === null) {
            Log::warning('mercadopago.webhook.pago_sena_no_encontrado_por_orden', [
                'user_id' => $credencial->user_id,
                'order_id' => $orderId,
            ]);

            return response()->json(['ok' => true]);
        }

        $reserva = $pagoSena->reservaWeb;

        $pago = $this->mercadoPago->buscarPagoPorExternalReference($credencial, $reserva->public_token);
        if ($pago === null) {
            // 500 a proposito, igual que el camino 'payment': que MP reintente.
            abort(500, 'No se pudo consultar el pago de la orden.');
        }

        $this->mercadoPago->sincronizarPago($pagoSena, $reserva, $pago);

        return response()->json(['ok' => true]);
    }
}

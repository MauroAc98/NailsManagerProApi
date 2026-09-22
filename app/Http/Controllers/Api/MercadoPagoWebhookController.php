<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PagoSena;
use App\Models\ReservaWeb;
use App\Models\UserMpCredential;
use App\Services\Reservas\ConfirmacionResultado;
use App\Services\Reservas\ConfirmarReservaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
    public function __construct(private ConfirmarReservaService $confirmar) {}

    // POST /api/webhooks/mercadopago/{ruteo}
    public function handle(Request $request, string $ruteo): JsonResponse
    {
        $credencial = UserMpCredential::where('webhook_ruteo', $ruteo)->first();
        if ($credencial === null) {
            abort(404);
        }

        // Solo nos interesan notificaciones de pago; todo lo demas se
        // reconoce sin procesar (nunca 500, MP no debe reintentar por esto).
        $tipo = $request->input('type') ?? $request->input('topic');
        if ($tipo !== null && $tipo !== 'payment') {
            return response()->json(['ok' => true]);
        }

        $paymentId = $request->input('data.id') ?? $request->query('data.id');
        if (! $paymentId) {
            return response()->json(['ok' => true]);
        }

        $pago = $this->consultarPago($credencial, (string) $paymentId);

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

        $this->aplicarEstado($pagoSena, $reserva, (string) $paymentId, (string) ($pago['status'] ?? ''));

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function consultarPago(UserMpCredential $credencial, string $paymentId): array
    {
        $response = Http::withToken($credencial->mp_access_token)
            ->timeout(15)
            ->get("https://api.mercadopago.com/v1/payments/{$paymentId}");

        if (! $response->successful()) {
            Log::error('mercadopago.webhook.consulta_fallo', [
                'user_id' => $credencial->user_id,
                'payment_id' => $paymentId,
                'status' => $response->status(),
            ]);

            // 500 a proposito: que Mercado Pago reintente la notificacion.
            abort(500, 'No se pudo consultar el pago.');
        }

        return $response->json() ?? [];
    }

    private function aplicarEstado(PagoSena $pagoSena, ReservaWeb $reserva, string $paymentId, string $status): void
    {
        // Idempotente: una notificacion repetida de un pago ya aprobado no
        // vuelve a intentar confirmar (ConfirmarReservaService ya lo es, pero
        // evita ruido/trabajo de mas).
        if ($pagoSena->estaAprobado()) {
            return;
        }

        $estado = match ($status) {
            'approved' => 'aprobado',
            'rejected', 'cancelled' => 'rechazado',
            default => 'pendiente',
        };

        $pagoSena->update(['mp_payment_id' => $paymentId, 'estado' => $estado]);

        if ($estado !== 'aprobado') {
            return;
        }

        $resultado = $this->confirmar->confirmar($reserva, Carbon::now());

        if ($resultado->resultado === ConfirmacionResultado::NEEDS_REFUND) {
            // El dinero SI se cobro (el pago esta aprobado) pero el horario ya
            // no se puede dar: ConfirmarReservaService ya marco
            // requiere_reembolso. No hay reembolso automatico todavia — TODO
            // fase futura; por ahora es un aviso para resolver a mano.
            Log::error('mercadopago.webhook.pago_aprobado_requiere_reembolso', [
                'reserva_id' => $reserva->id,
                'payment_id' => $paymentId,
                'motivo' => $resultado->motivo,
            ]);
        }
    }
}

<?php

namespace App\Services\Reservas;

use App\Exceptions\ReservaPublicaException;
use App\Models\PagoSena;
use App\Models\UserMpCredential;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * El webhook de Mercado Pago puede no llegar nunca (caida nuestra, caida de
 * MP, o MP directamente no lo manda) — por eso no alcanza con esperarlo.
 * Este servicio recorre los pagos que quedaron 'pendiente' hace rato y vuelve
 * a preguntarle a MP por ellos, reusando la MISMA logica de aplicacion de
 * estado que el webhook (MercadoPagoService::sincronizarPago) para que las
 * dos vias nunca queden con comportamiento distinto.
 */
class ReconciliarPagosService
{
    // Margen antes de tocar un pago 'pendiente': el webhook es el camino
    // normal y suele llegar en segundos — reconciliar antes de tiempo solo
    // generaria llamadas de mas a la API de MP por cada pago en curso.
    private const MINUTOS_DE_GRACIA = 2;

    public function __construct(private MercadoPagoService $mercadoPago) {}

    public function reconciliarPendientes(): int
    {
        $pendientes = PagoSena::where('estado', 'pendiente')
            ->where('created_at', '<=', Carbon::now()->subMinutes(self::MINUTOS_DE_GRACIA))
            ->get();

        $procesados = 0;
        foreach ($pendientes as $pago) {
            if ($this->reconciliarUno($pago)) {
                $procesados++;
            }
        }

        return $procesados;
    }

    private function reconciliarUno(PagoSena $pago): bool
    {
        $reserva = $pago->reservaWeb;
        $credencial = $reserva?->user?->mpCredentials;
        if ($reserva === null || $credencial === null) {
            Log::warning('mercadopago.reconciliar.sin_credencial', ['pago_sena_id' => $pago->id]);

            return false;
        }

        $datos = $pago->mp_payment_id !== null
            ? $this->consultarConTolerancia($credencial, $pago)
            : $this->mercadoPago->buscarPagoPorExternalReference($credencial, $reserva->public_token);

        if ($datos === null) {
            return false;
        }

        $this->mercadoPago->sincronizarPago($pago, $reserva, $datos);

        return true;
    }

    /** @return array<string, mixed>|null */
    private function consultarConTolerancia(UserMpCredential $credencial, PagoSena $pago): ?array
    {
        try {
            return $this->mercadoPago->consultarPago($credencial, $pago->mp_payment_id);
        } catch (ReservaPublicaException $e) {
            // Una fila con problemas no debe frenar el resto del lote: se
            // reintenta en la proxima corrida programada.
            Log::error('mercadopago.reconciliar.consulta_fallo', [
                'pago_sena_id' => $pago->id,
                'payment_id' => $pago->mp_payment_id,
                'codigo' => $e->codigo,
            ]);

            return null;
        }
    }
}

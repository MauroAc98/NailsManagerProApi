<?php

namespace App\Services\Reservas;

use App\Models\ReservaWeb;
use Illuminate\Support\Facades\Log;

/**
 * Ordena los holds vencidos (estado = expired) y aplica la reputacion. La
 * correccion NO depende de esto: las consultas de disponibilidad filtran
 * siempre por expira_en. Idempotente: el UPDATE es condicional al estado, asi
 * dos corridas (o el job y la expiracion lazy) no penalizan dos veces.
 */
class ExpirarHoldsService
{
    public function __construct(private ReputacionService $reputacion)
    {
    }

    /**
     * @param  string|null  $deviceHash  si viene, solo los holds de ese dispositivo (expiracion lazy)
     * @return int cantidad de holds marcados como expirados
     */
    public function expirarVencidos(int $ahoraEpoch, ?string $deviceHash = null): int
    {
        $query = ReservaWeb::bloqueantes()->where('expira_en', '<=', $ahoraEpoch);
        if ($deviceHash !== null) {
            $query->where('device_hash', $deviceHash);
        }

        $total = 0;
        $query->chunkById(200, function ($reservas) use ($ahoraEpoch, &$total) {
            foreach ($reservas as $reserva) {
                if ($this->expirarUna($reserva, $ahoraEpoch)) {
                    $total++;
                }
            }
        });

        return $total;
    }

    /** Expira una reserva concreta si sigue viva en estado y ya vencio. */
    public function expirarUna(ReservaWeb $reserva, int $ahoraEpoch): bool
    {
        $afectadas = ReservaWeb::where('id', $reserva->id)
            ->bloqueantes()
            ->where('expira_en', '<=', $ahoraEpoch)
            ->update(['estado' => 'expired', 'motivo_cierre' => 'vencido', 'updated_at' => now()]);

        if ($afectadas === 0) {
            return false;
        }

        $phoneHash = $reserva->telefono ? $this->reputacion->hashTelefono($reserva->telefono) : null;
        if ($reserva->device_hash !== null || $phoneHash !== null) {
            $this->reputacion->registrarVencido($reserva->user_id, $reserva->device_hash, $phoneHash, $ahoraEpoch);
        }

        Log::info('reserva.hold.expired', [
            'user_id' => $reserva->user_id,
            'profesional_id' => $reserva->profesional_id,
            'reserva_id' => $reserva->id,
            'device_prefix' => $reserva->device_hash ? substr($reserva->device_hash, 0, 8) : null,
            'motivo' => 'vencido',
        ]);

        return true;
    }
}

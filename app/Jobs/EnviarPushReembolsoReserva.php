<?php

namespace App\Jobs;

use App\Models\ReservaWeb;
use App\Notifications\ReservaRequiereReembolso;
use App\Services\Push\ReembolsoPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Web Push to the salon owner when a client PAID the deposit but the booking
 * could not be confirmed (requiere_reembolso). Best-effort like
 * EnviarPushReservaOnline: never throws, never retries.
 */
class EnviarPushReembolsoReserva implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $reservaId)
    {
    }

    public function handle(): void
    {
        try {
            $reserva = ReservaWeb::with('user')->find($this->reservaId);
            $user = $reserva?->user;

            if (! $reserva || ! $user || $user->pushSubscriptions()->doesntExist()) {
                return;
            }

            $user->notify(new ReservaRequiereReembolso(ReembolsoPayload::para($reserva)));
        } catch (\Throwable $e) {
            Log::warning('EnviarPushReembolsoReserva: fallo al enviar el push', [
                'reserva_id' => $this->reservaId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

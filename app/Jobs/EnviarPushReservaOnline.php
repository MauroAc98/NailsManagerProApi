<?php

namespace App\Jobs;

use App\Models\Turno;
use App\Notifications\NuevaReservaOnline;
use App\Services\Push\ReservaOnlinePayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Web Push "nueva reserva online" to every subscribed device of the salon
 * account that owns the turno. One job per reservation (a grupo of turnos is
 * a single notification). Best-effort: it never throws and never retries, so a
 * push problem can neither affect the booking nor produce duplicate pushes.
 * Expired subscriptions (404/410) are removed by the web-push channel.
 */
class EnviarPushReservaOnline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $turnoId)
    {
    }

    public function handle(): void
    {
        try {
            $turno = Turno::with('user')->find($this->turnoId);
            $user = $turno?->user;

            if (! $turno || ! $user || $user->pushSubscriptions()->doesntExist()) {
                return;
            }

            $user->notify(new NuevaReservaOnline(ReservaOnlinePayload::para($turno)));
        } catch (\Throwable $e) {
            Log::warning('EnviarPushReservaOnline: fallo al enviar el push', [
                'turno_id' => $this->turnoId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Notifications\ResumenMensajesWhatsapp;
use App\Services\Push\ResumenWhatsappPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Web Push "N messages delivered" summary for one salon account and one
 * category (confirmaciones / recordatorios). The webhook debounces the
 * dispatch (one delayed job per user + category per window), and the counts
 * are read here at run time so the summary covers the whole burst. Failures
 * already have their own push, so a summary with nothing delivered is skipped.
 * Best-effort: it never throws and never retries.
 */
class EnviarPushResumenWhatsapp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $userId, public string $categoria)
    {
    }

    public function handle(): void
    {
        try {
            $tipos = ResumenWhatsappPayload::tipos($this->categoria);
            $user = User::find($this->userId);

            if (! $tipos || ! $user || $user->pushSubscriptions()->doesntExist()) {
                return;
            }

            // Same "today" the notifications panel (TurnoController::notificaciones) uses.
            $deHoy = WhatsappMensaje::where('user_id', $user->id)
                ->whereIn('tipo', $tipos)
                ->whereDate('created_at', now()->toDateString());

            $entregados = (clone $deHoy)->whereIn('status', ['delivered', 'read'])->count();

            if ($entregados === 0) {
                return;
            }

            $fallaron = (clone $deHoy)->where('status', 'failed')->count();

            $user->notify(new ResumenMensajesWhatsapp(
                ResumenWhatsappPayload::para($this->categoria, $entregados, $fallaron),
            ));
        } catch (\Throwable $e) {
            Log::warning('EnviarPushResumenWhatsapp: fallo al enviar el push', [
                'user_id' => $this->userId,
                'categoria' => $this->categoria,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

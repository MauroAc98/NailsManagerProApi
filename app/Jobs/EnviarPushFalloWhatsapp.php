<?php

namespace App\Jobs;

use App\Models\WhatsappMensaje;
use App\Notifications\FalloMensajeWhatsapp;
use App\Services\Push\FalloWhatsappPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Web Push "a WhatsApp message could not be delivered" to every subscribed
 * device of the salon account that owns the message. One job per failed
 * message. Best-effort: it never throws and never retries, so a push problem
 * can neither affect the WhatsApp webhook nor produce duplicate pushes.
 * Expired subscriptions (404/410) are removed by the web-push channel.
 */
class EnviarPushFalloWhatsapp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $mensajeId)
    {
    }

    /**
     * Encola el push de fallo sin que un problema de cola rompa al llamador
     * (el job de confirmación o el comando de recordatorios). Lo usan los
     * fallos de NUESTRO lado, que no pasan por el webhook de Meta.
     */
    public static function encolarSinRomper(int $mensajeId): void
    {
        try {
            self::dispatch($mensajeId);
        } catch (\Throwable $e) {
            Log::warning('whatsapp.push_fallo_no_encolado', [
                'whatsapp_mensaje_id' => $mensajeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handle(): void
    {
        try {
            $mensaje = WhatsappMensaje::with(['user', 'turno.cliente', 'turno.servicios'])->find($this->mensajeId);
            $user = $mensaje?->user;

            if (! $mensaje || ! $user || $user->pushSubscriptions()->doesntExist()) {
                return;
            }

            $user->notify(new FalloMensajeWhatsapp(FalloWhatsappPayload::para($mensaje)));
        } catch (\Throwable $e) {
            Log::warning('EnviarPushFalloWhatsapp: fallo al enviar el push', [
                'whatsapp_mensaje_id' => $this->mensajeId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

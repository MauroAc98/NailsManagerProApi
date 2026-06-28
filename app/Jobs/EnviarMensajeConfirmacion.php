<?php

namespace App\Jobs;

use App\Models\Turno;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappTemplate;
use App\Services\EvolutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnviarMensajeConfirmacion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 5;
    public int $backoff = 15;

    public function __construct(
        public int $turnoId,
    ) {}

    public function handle(EvolutionService $evolutionService): void
    {
        $turno = Turno::with(['cliente', 'servicios', 'user'])->find($this->turnoId);

        if (!$turno) {
            Log::warning('EnviarMensajeConfirmacion: turno no encontrado', ['turno_id' => $this->turnoId]);
            return;
        }

        $user    = $turno->user;
        $cliente = $turno->cliente;

        if (!$user || !$cliente || empty($cliente->telefono)) {
            Log::warning('EnviarMensajeConfirmacion: faltan datos', ['turno_id' => $this->turnoId]);
            return;
        }

        if (!$user->tieneWhatsappConectado()) {
            return;
        }

        // ── Verificar que la conexión esté estable en Evolution API ──
        $estadoReal = $evolutionService->consultarEstado($user);

        if ($estadoReal !== 'open') {
            Log::info('EnviarMensajeConfirmacion: conexión no estable, reintentando', [
                'turno_id' => $this->turnoId,
                'estado'   => $estadoReal,
            ]);
            $this->release(30);
            return;
        }

        $plantilla = WhatsappTemplate::obtenerPlantilla($user, 'confirmacion');
        $mensaje   = WhatsappTemplate::procesarPlantilla($plantilla, $cliente, $turno, $user);
        $numero    = preg_replace('/\D/', '', $cliente->telefono);

        $messageId = $evolutionService->enviarMensaje($user, $numero, $mensaje);

        // ── Guardar registro para tracking ──
        WhatsappMensaje::create([
            'user_id'        => $user->id,
            'turno_id'       => $turno->id,
            'numero'         => $numero,
            'mensaje'        => $mensaje,
            'tipo'           => 'confirmacion',
            'message_id'     => $messageId,
            'status'         => $messageId ? 'pending' : 'failed',
            'intentos'       => 1,
            'ultimo_intento' => now(),
        ]);

        if (!$messageId) {
            Log::error('EnviarMensajeConfirmacion: fallo al enviar', [
                'turno_id' => $this->turnoId,
                'user_id'  => $user->id,
            ]);
        }
    }
}
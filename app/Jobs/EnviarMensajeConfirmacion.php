<?php

namespace App\Jobs;

use App\Models\Turno;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappTemplate;
use App\Services\CloudApiService;
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

    public int $tries = 5;

    public int $backoff = 15;

    public function __construct(
        public int $turnoId,
    ) {}

    public function handle(EvolutionService $evolutionService, CloudApiService $cloudApiService): void
    {
        $turno = Turno::with(['cliente', 'servicios', 'user', 'profesional'])->find($this->turnoId);

        if (! $turno) {
            Log::warning('EnviarMensajeConfirmacion: turno no encontrado', ['turno_id' => $this->turnoId]);

            return;
        }

        $user = $turno->user;
        $cliente = $turno->cliente;

        if (! $user || ! $cliente || empty($cliente->telefono)) {
            Log::warning('EnviarMensajeConfirmacion: faltan datos', ['turno_id' => $this->turnoId]);

            return;
        }

        if ($cliente->whatsapp_opt_out) {
            return;
        }

        // Evitar duplicar el mensaje si el job se reintenta (timeout de worker,
        // etc.) después de haber enviado y registrado exitosamente.
        $yaEnviado = WhatsappMensaje::where('turno_id', $turno->id)
            ->where('tipo', 'confirmacion')
            ->exists();

        if ($yaEnviado) {
            return;
        }

        // La plantilla aprobada en Meta solo tiene versión es_AR — sin eso,
        // una profesional en pt-BR con provider cloud_api cae a Evolution.
        $usaCloudApi = $user->whatsapp_provider === 'cloud_api' && $user->locale !== 'pt-BR';

        if ($usaCloudApi) {
            $mensaje = WhatsappTemplate::procesarPlantilla(
                WhatsappTemplate::obtenerPlantilla($user, 'confirmacion'),
                $cliente,
                $turno,
                $user,
            );
            $numero = $cloudApiService->normalizarNumero($cliente->telefono);

            $messageId = $cloudApiService->enviarPlantilla(
                $numero,
                WhatsappTemplate::nombrePlantillaMeta('confirmacion'),
                'es_AR',
                WhatsappTemplate::parametrosCloudApi('confirmacion', $cliente, $turno, $user),
            );
        } else {
            if ($user->whatsapp_provider === 'cloud_api') {
                Log::info('EnviarMensajeConfirmacion: provider cloud_api sin plantilla en el locale, usando Evolution', [
                    'turno_id' => $this->turnoId,
                    'user_id' => $user->id,
                    'locale' => $user->locale,
                ]);
            }

            if (! $user->tieneWhatsappConectado()) {
                return;
            }

            if ($user->whatsapp_requiere_envio_manual) {
                return;
            }

            // ── Verificar que la conexión esté estable en Evolution API ──
            $estadoReal = $evolutionService->consultarEstado($user);

            if ($estadoReal !== 'open') {
                Log::info('EnviarMensajeConfirmacion: conexión no estable, reintentando', [
                    'turno_id' => $this->turnoId,
                    'estado' => $estadoReal,
                ]);
                $this->release(30);

                return;
            }

            $plantilla = WhatsappTemplate::obtenerPlantilla($user, 'confirmacion');
            $mensaje = WhatsappTemplate::procesarPlantilla($plantilla, $cliente, $turno, $user);
            $numero = $evolutionService->normalizarNumero($cliente->telefono);

            $messageId = $evolutionService->enviarMensaje($user, $numero, $mensaje);
        }

        // ── Guardar registro para tracking ──
        try {
            WhatsappMensaje::create([
                'user_id' => $user->id,
                'turno_id' => $turno->id,
                'numero' => $numero,
                'mensaje' => $mensaje,
                'tipo' => 'confirmacion',
                'message_id' => $messageId,
                'status' => $messageId ? 'pending' : 'failed',
                'intentos' => 1,
                'ultimo_intento' => now(),
            ]);
        } catch (\Throwable $e) {
            if ($messageId) {
                // El mensaje ya salió por WhatsApp. No dejamos que la
                // excepción tire el job a retry: un reintento acá volvería
                // a llamar a enviarMensaje() y mandaría un duplicado real
                // al cliente solo porque se perdió el registro de tracking.
                Log::error('EnviarMensajeConfirmacion: mensaje enviado pero falló el guardado del registro — no se reintenta para evitar duplicado', [
                    'turno_id' => $this->turnoId,
                    'user_id' => $user->id,
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            // No se envió nada todavía: es seguro dejar que el job reintente.
            throw $e;
        }

        if (! $messageId) {
            try {
                Log::error('EnviarMensajeConfirmacion: fallo al enviar', [
                    'turno_id' => $this->turnoId,
                    'user_id' => $user->id,
                ]);
            } catch (\Throwable $logError) {
                // no-op: el estado ya se guardó, el log es best-effort
            }
        }
    }
}

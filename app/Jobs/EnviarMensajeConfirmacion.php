<?php

namespace App\Jobs;

use App\Models\Turno;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappTemplate;
use App\Services\CloudApiService;
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

    // Mismo regex que valida el teléfono al cargar el cliente
    // (ClienteController@store/update) — una verificación extra acá antes
    // de gastar un llamado a la API, para datos que puedan haber quedado
    // mal cargados por otra vía.
    private const REGEX_TELEFONO = '/^\+[1-9]\d{7,14}$/';

    public function __construct(
        public int $turnoId,
    ) {}

    public function handle(CloudApiService $cloudApiService): void
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

        if (! $user->confirmacion_automatica) {
            return;
        }

        // No seguir mandando mensajes pagos (Cloud API cobra por envío) a
        // cuentas con la suscripción vencida y sin pagar.
        if ($user->suscripcionVencida()) {
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

        if ($user->whatsapp_requiere_envio_manual) {
            Log::info('EnviarMensajeConfirmacion: requiere envío manual, confirmación automática omitida', [
                'turno_id' => $this->turnoId,
                'user_id' => $user->id,
            ]);

            return;
        }

        if (! preg_match(self::REGEX_TELEFONO, $cliente->telefono)) {
            Log::warning('EnviarMensajeConfirmacion: teléfono de cliente con formato inválido, omitido', [
                'turno_id' => $this->turnoId,
                'user_id' => $user->id,
            ]);

            return;
        }

        // Los salones que activaron el opt-in de seña usan la plantilla
        // reserva_turno_sena (10 vars: agrega monto y datos de la cuenta).
        // El resto sigue con confirmacion_turno (8 vars). La categoría del
        // registro (WhatsappMensaje.tipo) queda en 'confirmacion' en ambos
        // casos — es el tipo de evento para tracking/dedup, no el nombre de
        // la plantilla.
        $templateTipo = $user->whatsapp_pide_sena ? 'reserva_sena' : 'confirmacion';

        $mensaje = WhatsappTemplate::mensajeLegible($templateTipo, $cliente, $turno, $user);
        $numero = $cloudApiService->normalizarNumero($cliente->telefono);

        $resultado = $cloudApiService->enviarPlantilla(
            $numero,
            WhatsappTemplate::nombrePlantillaMeta($templateTipo),
            'es_AR',
            WhatsappTemplate::parametrosCloudApi($templateTipo, $cliente, $turno, $user),
        );
        $messageId = $resultado->messageId;

        // ── Guardar registro para tracking ──
        try {
            WhatsappMensaje::create([
                'user_id' => $user->id,
                'turno_id' => $turno->id,
                'numero' => $numero,
                'provider' => 'cloud_api',
                'mensaje' => $mensaje,
                'tipo' => 'confirmacion',
                'message_id' => $messageId,
                'status' => $messageId ? 'pending' : 'failed',
                'respuesta_api' => $resultado->respuesta,
                'status_code' => $resultado->statusCode,
            ]);
        } catch (\Throwable $e) {
            if ($messageId) {
                // El mensaje ya salió por WhatsApp. No dejamos que la
                // excepción tire el job a retry: un reintento acá volvería
                // a llamar a enviarPlantilla() y mandaría un duplicado real
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

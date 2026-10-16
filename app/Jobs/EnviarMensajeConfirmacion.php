<?php

namespace App\Jobs;

use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappTemplate;
use App\Services\CloudApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EnviarMensajeConfirmacion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 15;

    // Si TODOS los reintentos lanzan (timeout, 5xx, red caída) no se escribió
    // ningún WhatsappMensaje: ver failed(), que deja el rastro y avisa.

    // Mismo regex que valida el teléfono al cargar el cliente
    // (ClienteController@store/update) — una verificación extra acá antes
    // de gastar un llamado a la API, para datos que puedan haber quedado
    // mal cargados por otra vía.
    private const REGEX_TELEFONO = '/^\+[1-9]\d{7,14}$/';

    // `tipo` = 'confirmacion' (default, alta de turno) o 'reprogramacion' (aviso
    // de cambio de horario de un grupo, PR 6f): misma plantilla `confirmacion`,
    // distinto registro en whatsapp_mensajes y sin la variante de seña.
    // Declarada con valor por defecto de clase (no promovida): un job serializado
    // antes de que existiera `tipo` se deserializa sin pasar por el constructor y
    // tiene que quedar como 'confirmacion' en vez de una propiedad sin inicializar.
    public string $tipo = 'confirmacion';

    // Máximo de caracteres del cuerpo de una plantilla de WhatsApp (Meta). Si el
    // texto combinado de un lote lo supera, no se manda: se confirma turno por turno.
    private const LIMITE_CUERPO = 1024;

    // TTL del candado de envío. enviarPlantilla() fija un timeout de 15 s
    // (CloudApiService::TIMEOUT_PLANTILLA_SEGUNDOS): el TTL lo supera con
    // margen para que el lock no expire a mitad de un envío.
    private const LOCK_SEGUNDOS = 90;

    // true = confirmación individual de UN turno de un lote (fallback por longitud):
    // no usa el camino de lote y deduplica solo por turno, así las confirmaciones
    // de los demás turnos del lote no se bloquean entre sí. Mismo criterio que
    // `tipo`: valor por defecto de clase para jobs serializados antes de existir.
    public bool $porTurno = false;

    public function __construct(
        public int $turnoId,
        string $tipo = 'confirmacion',
        bool $porTurno = false,
    ) {
        $this->tipo = $tipo;
        $this->porTurno = $porTurno;
    }

    /**
     * Motivo por el que NO se mandaria el mensaje a este turno (null = se manda).
     * Refleja las guardas de handle() para poder informarlas en el momento
     * (p. ej. al reprogramar), sin esperar al job.
     */
    public static function motivoOmision(Turno $turno): ?string
    {
        $user = $turno->user;
        $cliente = $turno->cliente;

        return match (true) {
            ! $user || ! $cliente || empty($cliente->telefono) => 'sin_telefono',
            (bool) $cliente->whatsapp_opt_out => 'opt_out',
            ! $user->confirmacion_automatica => 'confirmacion_automatica_apagada',
            $user->suscripcionVencida() => 'suscripcion_vencida',
            (bool) $user->whatsapp_requiere_envio_manual => 'envio_manual',
            ! preg_match(self::REGEX_TELEFONO, $cliente->telefono) => 'telefono_invalido',
            default => null,
        };
    }

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

        // Candado atómico por unidad de envío: cubre desde el chequeo de dedup
        // hasta DESPUÉS de crear el WhatsappMensaje. Sin él, dos jobs de la misma
        // unidad corriendo a la vez (varios workers, reintento tras falla de cola,
        // fallback de longitud) pasan ambos el chequeo y mandan un duplicado real.
        // get(callback) libera el lock siempre, aunque el callback lance.
        $esDeLote = $turno->lote_id !== null && $this->tipo === 'confirmacion' && ! $this->porTurno;
        $lock = Cache::lock($this->claveLock($turno, $esDeLote), self::LOCK_SEGUNDOS);

        if ($lock->get(fn () => $this->enviar($cloudApiService, $turno, $esDeLote)) === false) {
            // Otro job de la misma unidad está enviando: se reintenta más tarde
            // (el reintento verá el registro y deduplica solo). Sin job de cola
            // (dispatchSync/tests directos) no hay a dónde liberar: se retorna.
            if ($this->job) {
                $this->release($this->backoff);
            }
        }
    }

    // ¿Ya hay un WhatsappMensaje de este tipo para la unidad de envío? (turno,
    // grupo o lote, con el mismo criterio que el candado). Lo usan el dedup de
    // enviar() y failed().
    private function yaTieneRegistro(Turno $turno, bool $esDeLote): bool
    {
        $idsDelGrupo = match (true) {
            $esDeLote => Turno::where('lote_id', $turno->lote_id)->pluck('id')->all(),
            $turno->grupo_id === null || $this->porTurno => [$turno->id],
            default => Turno::where('grupo_id', $turno->grupo_id)->pluck('id')->all(),
        };

        return WhatsappMensaje::whereIn('turno_id', $idsDelGrupo)
            ->where('tipo', $this->tipo)
            ->exists();
    }

    /**
     * Se agotaron los reintentos (timeout, 5xx, red caída...): nunca se llegó
     * a escribir un WhatsappMensaje y nadie se enteraba. Deja una fila
     * 'failed' como rastro y avisa a la dueña con el push de fallo. No duplica
     * si ya hay registro y no registra nada si el envío estaba omitido por
     * regla (opt-out, suscripción vencida...). Best-effort: nunca lanza.
     */
    public function failed(\Throwable $e): void
    {
        try {
            $turno = Turno::with(['cliente', 'servicios', 'user', 'profesional'])->find($this->turnoId);

            if (! $turno || self::motivoOmision($turno) !== null) {
                return;
            }

            $esDeLote = $turno->lote_id !== null && $this->tipo === 'confirmacion' && ! $this->porTurno;

            if ($this->yaTieneRegistro($turno, $esDeLote)) {
                return;
            }

            $user = $turno->user;
            $cliente = $turno->cliente;

            try {
                $mensaje = WhatsappTemplate::mensajeLegible('confirmacion', $cliente, $turno, $user);
            } catch (\Throwable) {
                $mensaje = '';
            }

            $registro = WhatsappMensaje::create([
                'user_id' => $user->id,
                'turno_id' => $turno->id,
                'numero' => app(CloudApiService::class)->normalizarNumero($cliente->telefono),
                'provider' => $user->credencialesWhatsapp()['provider'],
                'mensaje' => $mensaje,
                'tipo' => $this->tipo,
                'message_id' => null,
                'status' => 'failed',
                'error_titulo' => 'No se pudo enviar el mensaje por un error de conexión',
                'error_detalle' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::error('EnviarMensajeConfirmacion: reintentos agotados, queda registro fallido', [
                'turno_id' => $this->turnoId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            EnviarPushFalloWhatsapp::encolarSinRomper($registro->id);
        } catch (\Throwable $errorAlRegistrar) {
            try {
                Log::error('EnviarMensajeConfirmacion: reintentos agotados y no se pudo dejar registro', [
                    'turno_id' => $this->turnoId,
                    'error' => $e->getMessage(),
                    'error_al_registrar' => $errorAlRegistrar->getMessage(),
                ]);
            } catch (\Throwable) {
                // no-op: failed() es best-effort
            }
        }
    }

    // Una clave por unidad de envío, con el mismo criterio que el dedup:
    // lote (camino de lote), grupo (grupo_id sin porTurno) o turno + tipo.
    private function claveLock(Turno $turno, bool $esDeLote): string
    {
        return match (true) {
            $esDeLote => "wa-confirmacion:lote:{$turno->lote_id}:{$this->tipo}",
            $turno->grupo_id !== null && ! $this->porTurno => "wa-confirmacion:grupo:{$turno->grupo_id}:{$this->tipo}",
            default => "wa-confirmacion:turno:{$turno->id}:{$this->tipo}",
        };
    }

    private function enviar(CloudApiService $cloudApiService, Turno $turno, bool $esDeLote): void
    {
        $user = $turno->user;
        $cliente = $turno->cliente;

        // Evitar duplicar el mensaje si el job se reintenta (timeout de worker,
        // etc.) después de haber enviado y registrado exitosamente.
        // Turno de un grupo: la confirmacion es UNA por grupo, sea cual sea el
        // tramo por el que se dispare.
        // Turno de un lote (confirmacion): también UNA por lote, y el mensaje
        // combinado cubre todos los turnos vigentes. Reprogramación y
        // recordatorios no usan este camino: siguen siendo por turno.
        if ($this->yaTieneRegistro($turno, $esDeLote)) {
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
        // reserva_turno_sena_v2 (10 vars: agrega monto y datos de la cuenta).
        // El resto sigue con confirmacion_turno (8 vars). La categoría del
        // registro (WhatsappMensaje.tipo) queda en 'confirmacion' en ambos
        // casos — es el tipo de evento para tracking/dedup, no el nombre de
        // la plantilla.
        $templateTipo = $user->whatsapp_pide_sena && $this->tipo === 'confirmacion' ? 'reserva_sena' : 'confirmacion';

        // Backstop de datos: el guard de AuthController::updatePerfil ya
        // impide activar la seña sin monto/titular/medio-de-pago/dirección,
        // pero una edición directa de DB puede dejar whatsapp_pide_sena en
        // true con la config incompleta (p. ej. sena_monto null renderiza
        // "$0,00"). En ese caso se manda la confirmación simple en vez de
        // saltear el envío — el cliente igual recibe su confirmación.
        if ($templateTipo === 'reserva_sena' && ! $this->senaConfigCompleta($user)) {
            Log::warning('EnviarMensajeConfirmacion: whatsapp_pide_sena activo con config de seña incompleta, se envía confirmación simple', [
                'turno_id' => $this->turnoId,
                'user_id' => $user->id,
            ]);

            $templateTipo = 'confirmacion';
        }

        if ($esDeLote) {
            $vigentes = Turno::where('lote_id', $turno->lote_id)->where('estado', '!=', 'cancelado')
                ->orderBy('fecha_hora')->orderBy('id')->get();

            if ($vigentes->isEmpty()) {
                return;
            }

            if ($vigentes->count() === 1) {
                // Quedó un solo turno vigente (el disparado pudo ser el cancelado):
                // se confirma ese turno por el camino individual de siempre.
                $turno = Turno::with(['cliente', 'servicios', 'user', 'profesional'])->find($vigentes->first()->id);
            } else {
                $templateTipo = $templateTipo === 'reserva_sena' ? 'reserva_sena_lote' : 'confirmacion_lote';

                if (mb_strlen(WhatsappTemplate::mensajeLegible($templateTipo, $cliente, $turno, $user)) > self::LIMITE_CUERPO) {
                    Log::warning('EnviarMensajeConfirmacion: mensaje de lote supera el límite, se confirma turno por turno', [
                        'turno_id' => $this->turnoId,
                        'user_id' => $user->id,
                    ]);

                    foreach ($vigentes as $vigente) {
                        self::dispatch($vigente->id, $this->tipo, true);
                    }

                    return;
                }
            }
        }

        $mensaje = WhatsappTemplate::mensajeLegible($templateTipo, $cliente, $turno, $user);
        $numero = $cloudApiService->normalizarNumero($cliente->telefono);
        $credenciales = $user->credencialesWhatsapp();

        // El nombre de plantilla y el header salen del MISMO flag: nunca
        // pueden desacoplarse (una `_mapa` sin header, o un header sin
        // `_mapa`, es exactamente el bug que este acople evita).
        $conUbicacion = WhatsappTemplate::tieneUbicacion($user);

        $resultado = $cloudApiService->enviarPlantilla(
            $numero,
            WhatsappTemplate::nombrePlantillaMeta($templateTipo, $conUbicacion),
            'es_AR',
            WhatsappTemplate::parametrosCloudApi($templateTipo, $cliente, $turno, $user),
            token: $credenciales['token'],
            phoneNumberId: $credenciales['phone_number_id'],
            ubicacion: WhatsappTemplate::headerUbicacionCloudApi($user),
        );
        $messageId = $resultado->messageId;

        // ── Guardar registro para tracking ──
        try {
            $registro = WhatsappMensaje::create([
                'user_id' => $user->id,
                'turno_id' => $turno->id,
                'numero' => $numero,
                'provider' => $credenciales['provider'],
                'mensaje' => $mensaje,
                'tipo' => $this->tipo,
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

            // Meta rechazó el envío (token vencido, plantilla rechazada...): no
            // va a llegar ningún webhook de status, así que el push de fallo
            // sale desde acá. Best-effort: nunca rompe el job.
            EnviarPushFalloWhatsapp::encolarSinRomper($registro->id);
        }
    }

    // Mismas condiciones que el guard de AuthController::updatePerfil: la
    // plantilla reserva_turno_sena_v2 necesita monto > 0, titular, un medio de
    // pago (alias o CBU) y la dirección ({{6}}) para que Meta acepte el envío.
    private function senaConfigCompleta(User $user): bool
    {
        // El mensaje de seña por WhatsApp (plantilla Meta) solo sabe de un
        // monto fijo: en modo porcentaje se manda la confirmacion simple hasta
        // que una slice posterior lo soporte.
        return ! $user->senaEsPorcentaje()
            && is_numeric($user->sena_monto)
            && (float) $user->sena_monto > 0
            && filled($user->whatsapp_sena_titular)
            && (filled($user->whatsapp_sena_alias) || filled($user->whatsapp_sena_cbu))
            && filled($user->direccion);
    }
}

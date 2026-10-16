<?php

namespace App\Console\Commands;

use App\Jobs\EnviarPushFalloWhatsapp;
use App\Mail\RecordatoriosPendientesMail;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappTemplate;
use App\Services\CloudApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EnviarRecordatorios extends Command
{
    protected $signature = 'recordatorios:enviar';

    protected $description = 'Envía recordatorios de WhatsApp a las clientes con turno mañana';

    // Mismo regex que valida el teléfono al cargar el cliente
    // (ClienteController@store/update).
    private const REGEX_TELEFONO = '/^\+[1-9]\d{7,14}$/';

    public function __construct(
        private CloudApiService $cloudApiService,
    ) {
        parent::__construct();
    }

    // Latido de la última hora procesada ('Y-m-d H:00'). Si el scheduler estuvo
    // caído (o un deploy cayó justo en el :00), la corrida siguiente recupera las
    // horas salteadas en vez de dejar a esos salones sin recordatorios.
    public const CACHE_ULTIMA_HORA = 'recordatorios:ultima_hora';

    // Máximo de horas que una corrida procesa (la actual + las salteadas). Un
    // latido más viejo que esto no se recupera: se procesa solo la hora actual.
    private const MAX_HORAS_RECUPERADAS = 3;

    public function handle(): void
    {
        $ahora = Carbon::now()->startOfHour();
        $manana = Carbon::tomorrow()->toDateString();
        $horas = $this->horasAProcesar($ahora);

        $this->info('Hora actual: '.$ahora->format('H:00').' — horas a procesar: '.implode(', ', $horas)." — buscando recordatorios para {$manana}...");

        $usuarios = User::where('recordatorio_automatico', true)
            ->whereIn('hora_recordatorio', $horas)
            ->orderBy('hora_recordatorio')
            ->get();

        if ($usuarios->isEmpty()) {
            $this->info('No hay profesionales con recordatorio programado para esta hora.');
            $this->registrarLatido($ahora);

            return;
        }

        $this->info("Profesionales a notificar: {$usuarios->count()}");

        $this->totalEnviados = 0;
        $this->totalFallidos = 0;

        foreach ($usuarios as $user) {
            // Un salón que falla (SMTP caído, credenciales rotas, etc.) no puede
            // cortar la corrida: los salones que siguen perderían sus recordatorios.
            try {
                $this->procesarSalon($user, $manana);
            } catch (\Throwable $e) {
                $this->totalFallidos++;
                $this->error("  ✗ {$user->name}: {$e->getMessage()}");

                try {
                    Log::error('EnviarRecordatorios: falla al procesar el salón', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable $logError) {
                    // no-op: no dejar que un fallo de logging tumbe el resto de la corrida
                }
            }
        }

        $this->info('─────────────────────────────────');
        $this->info("✓ Enviados: {$this->totalEnviados}");
        if ($this->totalFallidos > 0) {
            $this->error("✗ Fallidos: {$this->totalFallidos}");
        }

        $this->registrarLatido($ahora);
    }

    /**
     * Horas ('H:00') que esta corrida debe atender: desde la hora siguiente al
     * último latido hasta la actual, con tope de MAX_HORAS_RECUPERADAS en total
     * y sin cruzar la medianoche (un recordatorio es "para mañana": ya cambió
     * el día objetivo). Sin latido, o con uno más viejo que el tope: solo la
     * hora actual, como siempre. No se usa una ventana "hora <= ahora": los
     * turnos agendados DESPUÉS de la hora configurada recibirían un recordatorio
     * a la hora de haberse confirmado; acá solo se recuperan horas realmente
     * salteadas. El dedupe por turno (whereDoesntHave) evita repetidos.
     *
     * @return list<string>
     */
    private function horasAProcesar(Carbon $ahora): array
    {
        $horas = [$ahora->format('H:00')];

        $ultima = $this->leerLatido();

        if (! $ultima || $ultima->gte($ahora)) {
            return $horas;
        }

        $horasDeDiferencia = (int) $ultima->diffInHours($ahora);

        if ($horasDeDiferencia > self::MAX_HORAS_RECUPERADAS) {
            return $horas;
        }

        $inicioDelDia = $ahora->copy()->startOfDay();
        $cursor = $ultima->copy()->addHour();

        $recuperadas = [];
        while ($cursor->lt($ahora)) {
            if ($cursor->gte($inicioDelDia)) {
                $recuperadas[] = $cursor->format('H:00');
            }
            $cursor->addHour();
        }

        return array_merge($recuperadas, $horas);
    }

    private function leerLatido(): ?Carbon
    {
        try {
            $valor = Cache::get(self::CACHE_ULTIMA_HORA);

            return is_string($valor) ? Carbon::createFromFormat('Y-m-d H:i', $valor)->startOfHour() : null;
        } catch (\Throwable $e) {
            // Sin latido legible se comporta como siempre (solo la hora actual).
            return null;
        }
    }

    private function registrarLatido(Carbon $ahora): void
    {
        try {
            Cache::put(self::CACHE_ULTIMA_HORA, $ahora->format('Y-m-d H:00'), now()->addDay());
        } catch (\Throwable $e) {
            // El latido es best-effort: perderlo solo significa no recuperar horas.
        }
    }

    private function procesarSalon(User $user, string $manana): void
    {
        // No seguir mandando mensajes pagos (Cloud API cobra por envío)
        // a cuentas con la suscripción vencida y sin pagar.
        if ($user->suscripcionVencida()) {
            $this->info("  → {$user->name}: suscripción vencida, recordatorios automáticos omitidos");

            return;
        }

        if ($user->whatsapp_requiere_envio_manual) {
            $this->info("  → {$user->name}: requiere envío manual, recordatorios automáticos omitidos");

            $turnosManana = Turno::delUsuario($user)
                ->confirmados()
                ->delaFecha($manana)
                ->whereDoesntHave('whatsappMensajes', fn ($q) => $q->where('tipo', 'recordatorio'))
                ->with('cliente')
                ->get()
                ->filter(fn ($turno) => ! empty($turno->cliente?->telefono));

            if ($turnosManana->isNotEmpty()) {
                Mail::to($user->email)->send(new RecordatoriosPendientesMail(
                    $user->name,
                    $turnosManana->count(),
                    rtrim(config('services.frontend_url'), '/').'/agenda/recordatorios',
                ));
            }

            return;
        }

        $turnos = Turno::delUsuario($user)
            ->with(['cliente', 'servicios', 'profesional'])
            ->confirmados()
            ->delaFecha($manana)
            ->whereDoesntHave('whatsappMensajes', fn ($q) => $q->where('tipo', 'recordatorio'))
            ->get();

        if ($turnos->isEmpty()) {
            $this->info("  → {$user->name}: sin turnos mañana, omitido");

            return;
        }

        $this->info("  → {$user->name}: {$turnos->count()} turno(s)");

        // Un grupo (varias profesionales) recibe UN solo recordatorio: se
        // saltea si algun tramo ya tiene uno gestionado, o ya se manejo en esta corrida.
        $gruposAvisados = Turno::whereIn('grupo_id', $turnos->pluck('grupo_id')->filter()->unique())
            ->whereHas('whatsappMensajes', fn ($q) => $q->where('tipo', 'recordatorio'))
            ->pluck('grupo_id')->all();

        // Resuelto una vez por negocio, no por turno: cada negocio manda
        // TODOS sus recordatorios de esta corrida por su propio número
        // (o el compartido, si no tiene conexión) — nunca mezclado.
        $credenciales = $user->credencialesWhatsapp();

        foreach ($turnos->sortBy('id') as $turno) {
            if ($turno->grupo_id !== null) {
                if (in_array($turno->grupo_id, $gruposAvisados, true)) {
                    continue;
                }
                $gruposAvisados[] = $turno->grupo_id;
            }

            $cliente = $turno->cliente;

            if (empty($cliente?->telefono)) {
                $this->warn("    ⚠ Turno #{$turno->id}: cliente sin teléfono, omitido");

                continue;
            }

            if ($cliente->whatsapp_opt_out) {
                $this->info("    → {$cliente->nombre} {$cliente->apellido}: dio de baja los recordatorios, omitido");

                continue;
            }

            if (! preg_match(self::REGEX_TELEFONO, $cliente->telefono)) {
                $this->warn("    ⚠ Turno #{$turno->id}: teléfono de cliente con formato inválido, omitido");

                Log::warning('EnviarRecordatorios: teléfono de cliente con formato inválido, omitido', [
                    'turno_id' => $turno->id,
                    'user_id' => $user->id,
                ]);

                continue;
            }

            $mensaje = WhatsappTemplate::mensajeLegible('recordatorio', $cliente, $turno, $user);
            $numero = $this->cloudApiService->normalizarNumero($cliente->telefono);

            // Misma derivación que EnviarMensajeConfirmacion: nombre de
            // plantilla y header salen del mismo flag, nunca desacoplados.
            $conUbicacion = WhatsappTemplate::tieneUbicacion($user);

            try {
                $resultado = $this->cloudApiService->enviarPlantilla(
                    $numero,
                    WhatsappTemplate::nombrePlantillaMeta('recordatorio', $conUbicacion),
                    'es_AR',
                    WhatsappTemplate::parametrosCloudApi('recordatorio', $cliente, $turno, $user),
                    token: $credenciales['token'],
                    phoneNumberId: $credenciales['phone_number_id'],
                    ubicacion: WhatsappTemplate::headerUbicacionCloudApi($user),
                );
                $messageId = $resultado->messageId;

                // ── Guardar registro para tracking ──
                $registro = WhatsappMensaje::create([
                    'user_id' => $user->id,
                    'turno_id' => $turno->id,
                    'numero' => $numero,
                    'provider' => $credenciales['provider'],
                    'mensaje' => $mensaje,
                    'tipo' => 'recordatorio',
                    'message_id' => $messageId,
                    'status' => $messageId ? 'pending' : 'failed',
                    'respuesta_api' => $resultado->respuesta,
                    'status_code' => $resultado->statusCode,
                ]);

                if ($messageId) {
                    $this->info("    ✓ {$cliente->nombre} {$cliente->apellido}");
                    $this->totalEnviados++;
                } else {
                    $this->error("    ✗ Fallo: {$cliente->nombre} {$cliente->apellido}");
                    $this->totalFallidos++;

                    // Meta rechazó el envío: no llegará ningún webhook de status,
                    // así que el push de fallo sale desde acá (best-effort).
                    EnviarPushFalloWhatsapp::encolarSinRomper($registro->id);
                }
            } catch (\Exception $e) {
                $this->error("    ✗ Error: {$e->getMessage()}");
                $this->totalFallidos++;

                try {
                    Log::error('EnviarRecordatorios: excepción', [
                        'turno_id' => $turno->id,
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable $logError) {
                    // no-op: no dejar que un fallo de logging tumbe el resto de la corrida
                }
            }
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Mail\RecordatoriosPendientesMail;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappTemplate;
use App\Services\CloudApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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

    public function handle(): void
    {
        $horaActual = Carbon::now()->format('H:00');
        $manana = Carbon::tomorrow()->toDateString();

        $this->info("Hora actual: {$horaActual} — buscando recordatorios para {$manana}...");

        $usuarios = User::where('recordatorio_automatico', true)
            ->where('hora_recordatorio', $horaActual)
            ->get();

        if ($usuarios->isEmpty()) {
            $this->info('No hay profesionales con recordatorio programado para esta hora.');

            return;
        }

        $this->info("Profesionales a notificar: {$usuarios->count()}");

        $totalEnviados = 0;
        $totalFallidos = 0;

        foreach ($usuarios as $user) {
            // No seguir mandando mensajes pagos (Cloud API cobra por envío)
            // a cuentas con la suscripción vencida y sin pagar.
            if ($user->suscripcionVencida()) {
                $this->info("  → {$user->name}: suscripción vencida, recordatorios automáticos omitidos");

                continue;
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

                continue;
            }

            $turnos = Turno::delUsuario($user)
                ->with(['cliente', 'servicios', 'profesional'])
                ->confirmados()
                ->delaFecha($manana)
                ->whereDoesntHave('whatsappMensajes', fn ($q) => $q->where('tipo', 'recordatorio'))
                ->get();

            if ($turnos->isEmpty()) {
                $this->info("  → {$user->name}: sin turnos mañana, omitido");

                continue;
            }

            $this->info("  → {$user->name}: {$turnos->count()} turno(s)");

            foreach ($turnos as $turno) {
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

                try {
                    $resultado = $this->cloudApiService->enviarPlantilla(
                        $numero,
                        WhatsappTemplate::nombrePlantillaMeta('recordatorio'),
                        'es_AR',
                        WhatsappTemplate::parametrosCloudApi('recordatorio', $cliente, $turno, $user),
                    );
                    $messageId = $resultado->messageId;

                    // ── Guardar registro para tracking ──
                    WhatsappMensaje::create([
                        'user_id' => $user->id,
                        'turno_id' => $turno->id,
                        'numero' => $numero,
                        'provider' => 'cloud_api',
                        'mensaje' => $mensaje,
                        'tipo' => 'recordatorio',
                        'message_id' => $messageId,
                        'status' => $messageId ? 'pending' : 'failed',
                        'respuesta_api' => $resultado->respuesta,
                        'status_code' => $resultado->statusCode,
                    ]);

                    if ($messageId) {
                        $this->info("    ✓ {$cliente->nombre} {$cliente->apellido}");
                        $totalEnviados++;
                    } else {
                        $this->error("    ✗ Fallo: {$cliente->nombre} {$cliente->apellido}");
                        $totalFallidos++;
                    }
                } catch (\Exception $e) {
                    $this->error("    ✗ Error: {$e->getMessage()}");
                    $totalFallidos++;

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

        $this->info('─────────────────────────────────');
        $this->info("✓ Enviados: {$totalEnviados}");
        if ($totalFallidos > 0) {
            $this->error("✗ Fallidos: {$totalFallidos}");
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Mail\RecordatoriosPendientesMail;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappTemplate;
use App\Services\CloudApiService;
use App\Services\EvolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EnviarRecordatorios extends Command
{
    protected $signature = 'recordatorios:enviar';

    protected $description = 'Envía recordatorios de WhatsApp a las clientes con turno mañana';

    public function __construct(
        private EvolutionService $evolutionService,
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
            ->where(function ($query) {
                $query->whereNotNull('evolution_instance_name')
                    ->orWhere('whatsapp_provider', 'cloud_api');
            })
            ->get();

        if ($usuarios->isEmpty()) {
            $this->info('No hay profesionales con recordatorio programado para esta hora.');

            return;
        }

        $this->info("Profesionales a notificar: {$usuarios->count()}");

        $totalEnviados = 0;
        $totalFallidos = 0;

        foreach ($usuarios as $user) {
            // La plantilla aprobada en Meta solo tiene versión es_AR — sin
            // eso, una profesional en pt-BR con provider cloud_api sigue el
            // camino Evolution de siempre (incluye su propio chequeo de
            // whatsapp_requiere_envio_manual más abajo).
            $usaCloudApi = $user->whatsapp_provider === 'cloud_api' && $user->locale !== 'pt-BR';

            if (! $usaCloudApi && $user->whatsapp_requiere_envio_manual) {
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

            if (! $usaCloudApi) {
                // Validar estado real contra Evolution, no confiar en whatsapp_estado
                // de la DB: puede quedar desincronizado si algún webhook se perdió.
                $estadoReal = $this->evolutionService->consultarEstado($user);
                $estadoSincronizado = match ($estadoReal) {
                    'open' => 'conectado',
                    'close' => 'desconectado',
                    default => 'conectando',
                };

                if ($user->whatsapp_estado !== $estadoSincronizado) {
                    $user->update(['whatsapp_estado' => $estadoSincronizado]);
                }

                if ($estadoReal !== 'open') {
                    $this->warn("  → {$user->name}: WhatsApp no conectado ({$estadoSincronizado}), omitido");

                    continue;
                }
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

            $plantilla = WhatsappTemplate::obtenerPlantilla($user, 'recordatorio');

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

                $mensaje = WhatsappTemplate::procesarPlantilla($plantilla, $cliente, $turno, $user);
                $numero = $usaCloudApi
                    ? $this->cloudApiService->normalizarNumero($cliente->telefono)
                    : $this->evolutionService->normalizarNumero($cliente->telefono);

                try {
                    $messageId = $usaCloudApi
                        ? $this->cloudApiService->enviarPlantilla(
                            $numero,
                            WhatsappTemplate::nombrePlantillaMeta('recordatorio'),
                            'es_AR',
                            WhatsappTemplate::parametrosCloudApi('recordatorio', $cliente, $turno, $user),
                        )
                        : $this->evolutionService->enviarMensaje($user, $numero, $mensaje);

                    // ── Guardar registro para tracking ──
                    WhatsappMensaje::create([
                        'user_id' => $user->id,
                        'turno_id' => $turno->id,
                        'numero' => $numero,
                        'mensaje' => $mensaje,
                        'tipo' => 'recordatorio',
                        'message_id' => $messageId,
                        'status' => $messageId ? 'pending' : 'failed',
                        'intentos' => 1,
                        'ultimo_intento' => now(),
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

                if (! $usaCloudApi) {
                    sleep(3);
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

<?php

namespace App\Console\Commands;

use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappTemplate;
use App\Services\EvolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class EnviarRecordatorios extends Command
{
    protected $signature = 'recordatorios:enviar';

    protected $description = 'Envía recordatorios de WhatsApp a las clientas con turno mañana';

    public function __construct(private EvolutionService $evolutionService)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $horaActual = Carbon::now()->format('H:00');
        $manana = Carbon::tomorrow()->toDateString();

        $this->info("Hora actual: {$horaActual} — buscando recordatorios para {$manana}...");

        $usuarios = User::where('recordatorio_automatico', true)
            ->where('hora_recordatorio', $horaActual)
            ->whereNotNull('evolution_instance_name')
            ->get();

        if ($usuarios->isEmpty()) {
            $this->info('No hay profesionales con recordatorio programado para esta hora.');

            return;
        }

        $this->info("Profesionales a notificar: {$usuarios->count()}");

        $totalEnviados = 0;
        $totalFallidos = 0;

        foreach ($usuarios as $user) {
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

            $turnos = Turno::delUsuario($user)
                ->with(['cliente', 'servicios', 'profesional'])
                ->confirmados()
                ->delaFecha($manana)
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
                    $this->warn("    ⚠ Turno #{$turno->id}: clienta sin teléfono, omitido");

                    continue;
                }

                if ($cliente->whatsapp_opt_out) {
                    $this->info("    → {$cliente->nombre} {$cliente->apellido}: dio de baja los recordatorios, omitido");

                    continue;
                }

                $mensaje = WhatsappTemplate::procesarPlantilla($plantilla, $cliente, $turno, $user);
                $numero = $this->evolutionService->normalizarNumero($cliente->telefono);

                try {
                    $messageId = $this->evolutionService->enviarMensaje($user, $numero, $mensaje);

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

                sleep(3);
            }
        }

        $this->info('─────────────────────────────────');
        $this->info("✓ Enviados: {$totalEnviados}");
        if ($totalFallidos > 0) {
            $this->error("✗ Fallidos: {$totalFallidos}");
        }
    }
}

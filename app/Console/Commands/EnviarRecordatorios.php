<?php

namespace App\Console\Commands;

use App\Models\Turno;
use App\Models\User;
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
        $manana     = Carbon::tomorrow()->toDateString();

        $this->info("Hora actual: {$horaActual} — buscando recordatorios para {$manana}...");

        // Solo usuarios cuya hora_recordatorio coincida con la hora actual
        $usuarios = User::where('recordatorio_automatico', true)
            ->where('whatsapp_estado', 'conectado')
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
            $turnos = Turno::delUsuario($user)
                ->with(['cliente', 'servicios'])
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

                $mensaje = WhatsappTemplate::procesarPlantilla(
                    $plantilla,
                    $cliente,
                    $turno,
                    $user
                );

                $numero = preg_replace('/\D/', '', $cliente->telefono);

                try {
                    $enviado = $this->evolutionService->enviarMensaje($user, $numero, $mensaje);

                    if ($enviado) {
                        $this->info("    ✓ {$cliente->nombre} {$cliente->apellido}");
                        $totalEnviados++;
                    } else {
                        $this->error("    ✗ Fallo: {$cliente->nombre} {$cliente->apellido}");
                        $totalFallidos++;
                        Log::error('EnviarRecordatorios: fallo al enviar', [
                            'turno_id' => $turno->id,
                            'user_id'  => $user->id,
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->error("    ✗ Error: {$e->getMessage()}");
                    $totalFallidos++;
                    Log::error('EnviarRecordatorios: excepción', [
                        'turno_id' => $turno->id,
                        'user_id'  => $user->id,
                        'error'    => $e->getMessage(),
                    ]);
                }

                sleep(3);
            }
        }

        $this->info("─────────────────────────────────");
        $this->info("✓ Enviados: {$totalEnviados}");
        if ($totalFallidos > 0) {
            $this->error("✗ Fallidos: {$totalFallidos}");
        }
    }
}
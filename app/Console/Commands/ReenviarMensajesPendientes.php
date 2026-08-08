<?php

namespace App\Console\Commands;

use App\Models\WhatsappMensaje;
use App\Services\EvolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReenviarMensajesPendientes extends Command
{
    protected $signature = 'whatsapp:reenviar-pendientes';
    protected $description = 'Reenvía mensajes de WhatsApp cuyo envío falló (sin message_id) hace más de 5 minutos';

    public function __construct(private EvolutionService $evolutionService)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $pendientes = WhatsappMensaje::pendientesParaReenviar()
            ->with(['user', 'turno.cliente'])
            ->get();

        if ($pendientes->isEmpty()) {
            return;
        }

        $this->info("Mensajes pendientes a reenviar: {$pendientes->count()}");

        foreach ($pendientes as $registro) {
            $user = $registro->user;

            if (!$user || !$user->tieneWhatsappConectado()) {
                $registro->update(['status' => 'failed']);
                continue;
            }

            if ($user->whatsapp_requiere_envio_manual) {
                $this->warn("  ⚠ {$user->name}: requiere envío manual, reintento omitido — {$registro->numero}");
                continue;
            }

            if ($registro->turno?->cliente?->whatsapp_opt_out) {
                $registro->update(['status' => 'failed']);
                $this->info("  → {$user->name}: destinatario dio de baja los mensajes, omitido — {$registro->numero}");
                continue;
            }

            // Verificar que la conexión esté estable antes de reenviar
            $estadoReal = $this->evolutionService->consultarEstado($user);

            if ($estadoReal !== 'open') {
                $this->warn("  ⚠ {$user->name}: conexión no estable, omitido");
                continue;
            }

            $messageId = $this->evolutionService->enviarMensaje(
                $user,
                $registro->numero,
                $registro->mensaje,
            );

            if ($messageId) {
                $registro->update([
                    'message_id'     => $messageId,
                    'status'         => 'pending',
                    'intentos'       => $registro->intentos + 1,
                    'ultimo_intento' => now(),
                ]);
                $this->info("  ✓ Reenviado (intento {$registro->intentos}) — {$registro->numero}");
            } else {
                $nuevosIntentos = $registro->intentos + 1;
                $registro->update([
                    'intentos'       => $nuevosIntentos,
                    'ultimo_intento' => now(),
                    'status'         => $nuevosIntentos >= 3 ? 'failed' : 'pending',
                ]);

                if ($nuevosIntentos >= 3) {
                    $this->error("  ✗ Fallido definitivamente — {$registro->numero}");

                    try {
                        Log::error('ReenviarMensajesPendientes: fallido definitivamente', [
                            'whatsapp_mensaje_id' => $registro->id,
                            'user_id'             => $user->id,
                            'numero'              => $registro->numero,
                            'tipo'                => $registro->tipo,
                        ]);
                    } catch (\Throwable $logError) {
                        // no-op: no dejar que un fallo de logging tumbe el resto de la corrida
                    }
                }
            }

            sleep(2);
        }
    }
}
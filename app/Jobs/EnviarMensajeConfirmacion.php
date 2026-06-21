<?php

namespace App\Jobs;

use App\Models\Turno;
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

    public int $tries = 3;
    public int $backoff = 10; // segundos entre reintentos

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

        // Si la profesional no tiene WhatsApp conectado, no hay nada que hacer.
        // No es un error — simplemente no tiene la función activada.
        if (!$user->tieneWhatsappConectado()) {
            return;
        }

        $fechaHora = \Illuminate\Support\Carbon::parse($turno->fecha_hora);
        $servicios = $turno->servicios->pluck('nombre')->join(' + ');

        $mensaje = str_replace(
            ['{nombre}', '{apellido}', '{servicios}', '{fecha}', '{hora}', '{negocio}'],
            [
                $cliente->nombre,
                $cliente->apellido,
                $servicios,
                $fechaHora->format('d/m'),
                $fechaHora->format('H:i'),
                $user->name,
            ],
            $user->mensaje_whatsapp,
        );

        $numero = preg_replace('/\D/', '', $cliente->telefono); // solo dígitos

        $enviado = $evolutionService->enviarMensaje($user, $numero, $mensaje);

        if (!$enviado) {
            Log::error('EnviarMensajeConfirmacion: fallo al enviar', [
                'turno_id' => $this->turnoId,
                'user_id'  => $user->id,
            ]);
        }
    }
}
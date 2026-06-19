<?php

namespace App\Console\Commands;

use App\Models\Turno;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CompletarTurnosVencidos extends Command
{
    protected $signature = 'turnos:completar-vencidos';

    protected $description = 'Marca como completado los turnos confirmados cuya duración ya finalizó.';

    public function handle(): int
    {
        $ahora = Carbon::now();

        // fecha_hora + duracion_total_minutos < ahora  →  ya terminó
        $turnos = Turno::where('estado', 'confirmado')
            ->whereRaw(
                "fecha_hora + (duracion_total_minutos || ' minutes')::interval < ?",
                [$ahora]
            )
            ->get();

        if ($turnos->isEmpty()) {
            $this->info('No hay turnos para completar.');
            return self::SUCCESS;
        }

        foreach ($turnos as $turno) {
            $turno->update(['estado' => 'completado']);
        }

        $this->info("Se completaron {$turnos->count()} turno(s).");

        return self::SUCCESS;
    }
}
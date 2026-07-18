<?php

namespace App\Console\Commands;

use App\Models\Profesional;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillProfesionalesDefault extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'profesionales:backfill-default';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea un Profesional default por cada usuario existente y reasigna sus slots, turnos y servicios (idempotente).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $usuariosProcesados = 0;

        User::query()->chunkById(100, function ($users) use (&$usuariosProcesados) {
            foreach ($users as $user) {
                DB::transaction(function () use ($user) {
                    // Idempotente: si el usuario ya tiene al menos un Profesional
                    // (porque el backfill ya corrió, o porque ya adoptó
                    // multi-agenda), no se crea un default nuevo.
                    $profesional = Profesional::where('user_id', $user->id)->first();

                    if (!$profesional) {
                        $profesional = Profesional::create([
                            'user_id' => $user->id,
                            'nombre'  => $user->name,
                            'activo'  => true,
                        ]);
                    }

                    SlotDisponible::where('user_id', $user->id)
                        ->whereNull('profesional_id')
                        ->update(['profesional_id' => $profesional->id]);

                    Turno::where('user_id', $user->id)
                        ->whereNull('profesional_id')
                        ->update(['profesional_id' => $profesional->id]);

                    $servicioIds = $user->servicios()->pluck('id');

                    if ($servicioIds->isNotEmpty()) {
                        $profesional->servicios()->syncWithoutDetaching($servicioIds);
                    }
                });

                $usuariosProcesados++;
            }
        });

        $this->info("Backfill completado. Usuarios procesados: {$usuariosProcesados}.");

        return self::SUCCESS;
    }
}

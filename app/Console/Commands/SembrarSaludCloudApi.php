<?php

namespace App\Console\Commands;

use App\Services\CloudApiService;
use Illuminate\Console\Command;

class SembrarSaludCloudApi extends Command
{
    protected $signature = 'whatsapp:sembrar-salud';

    protected $description = 'Comando manual, no programado: pide a Meta el quality_rating actual del número Cloud API y siembra el cache de salud usado por CloudApiService::estaSaludable(). Correr una vez después de deployar la infra de health-check, antes de que llegue el primer webhook de calidad.';

    public function handle(CloudApiService $cloudApi): int
    {
        $registro = $cloudApi->sembrarSalud();

        if ($registro === null) {
            $this->warn('No se pudo sembrar la salud: revisar los logs (permisos o transporte).');

            return self::SUCCESS;
        }

        $this->info("Salud sembrada: quality_rating={$registro['quality_rating']}");

        return self::SUCCESS;
    }
}

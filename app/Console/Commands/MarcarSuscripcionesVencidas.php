<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;

class MarcarSuscripcionesVencidas extends Command
{
    protected $signature = 'suscripciones:marcar-vencidas';

    protected $description = 'Pone status=VENCIDO en las suscripciones cuyo ends_at ya pasó — la columna no se actualiza sola, esto la mantiene consistente con lo que ya calculan en vivo AuthController::subscriptionStatus() y AdminController.';

    public function handle(): int
    {
        $actualizadas = Subscription::where('ends_at', '<', now())
            ->where('status', '!=', 'VENCIDO')
            ->update(['status' => 'VENCIDO']);

        if ($actualizadas === 0) {
            $this->info('No hay suscripciones para marcar como vencidas.');
            return self::SUCCESS;
        }

        $this->info("Se marcaron {$actualizadas} suscripción(es) como vencida(s).");

        return self::SUCCESS;
    }
}

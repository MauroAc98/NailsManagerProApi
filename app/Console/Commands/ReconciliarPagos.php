<?php

namespace App\Console\Commands;

use App\Services\Reservas\ReconciliarPagosService;
use Illuminate\Console\Command;

class ReconciliarPagos extends Command
{
    protected $signature = 'pagos:reconciliar';

    protected $description = 'Vuelve a consultar en Mercado Pago los pagos de sena que quedaron pendientes sin webhook';

    public function handle(ReconciliarPagosService $servicio): int
    {
        $n = $servicio->reconciliarPendientes();
        $this->info("Pagos reconciliados: {$n}");

        return self::SUCCESS;
    }
}

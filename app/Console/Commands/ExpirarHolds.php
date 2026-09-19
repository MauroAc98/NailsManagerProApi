<?php

namespace App\Console\Commands;

use App\Services\Reservas\ExpirarHoldsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ExpirarHolds extends Command
{
    protected $signature = 'reservas:expirar-holds';

    protected $description = 'Marca como expirados los holds de reserva online vencidos y aplica la reputacion anti-abuso';

    public function handle(ExpirarHoldsService $servicio): int
    {
        $n = $servicio->expirarVencidos(Carbon::now()->timestamp);
        $this->info("Holds expirados: {$n}");

        return self::SUCCESS;
    }
}

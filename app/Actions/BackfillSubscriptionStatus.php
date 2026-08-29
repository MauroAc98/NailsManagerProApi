<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

// Reconcilia la columna guardada subscriptions.status con lo que los caminos
// de lectura calculan en vivo a partir de ends_at. SUSPENDIDO es el único
// status que NO se deriva de ends_at, así que esas filas nunca se tocan.
// La usa la migración que ensancha el CHECK y es unit-testeable por separado.
class BackfillSubscriptionStatus
{
    /**
     * @return int cantidad de filas reconciliadas
     */
    public function handle(): int
    {
        $ahora = now();

        $aActivo = DB::table('subscriptions')
            ->where('status', '!=', 'SUSPENDIDO')
            ->where('status', '!=', 'ACTIVO')
            ->where('ends_at', '>', $ahora)
            ->update(['status' => 'ACTIVO']);

        $aVencido = DB::table('subscriptions')
            ->where('status', '!=', 'SUSPENDIDO')
            ->where('status', '!=', 'VENCIDO')
            ->where('ends_at', '<=', $ahora)
            ->update(['status' => 'VENCIDO']);

        return $aActivo + $aVencido;
    }
}

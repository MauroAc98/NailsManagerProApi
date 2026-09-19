<?php

/**
 * Worker del test de concurrencia opt-in (ReservaHoldsConcurrenciaPgsqlTest).
 * Arranca la app, espera el archivo de "largada" y trata de retener el mismo
 * horario. Imprime una linea JSON: {"ok": true|false, "code": "..."}.
 *
 * Uso: php tests/Support/hold_worker.php <user_id> <servicio_id> <profesional_id> <fecha> <hora> <device> <largada>
 */

use App\Exceptions\ReservaPublicaException;
use App\Models\User;
use App\Services\Reservas\HoldService;
use App\Services\Reservas\ReputacionService;
use Illuminate\Support\Carbon;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $userId, $servicioId, $profesionalId, $fecha, $hora, $device, $largada] = $argv;

$deadline = microtime(true) + 30;
while (! file_exists($largada) && microtime(true) < $deadline) {
    usleep(1000);
}

try {
    app(HoldService::class)->retener(
        User::findOrFail((int) $userId),
        [(int) $servicioId],
        (int) $profesionalId,
        $fecha,
        $hora,
        app(ReputacionService::class)->hashDevice($device),
        'worker-' . $device,
        Carbon::now(),
    );
    echo json_encode(['ok' => true, 'code' => null]) . "\n";
} catch (ReservaPublicaException $e) {
    echo json_encode(['ok' => false, 'code' => $e->codigo]) . "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'error:' . get_class($e)]) . "\n";
}

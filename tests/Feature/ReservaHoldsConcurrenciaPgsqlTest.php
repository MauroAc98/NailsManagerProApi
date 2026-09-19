<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * OPT-IN: solo corre con DB_CONNECTION=pgsql contra una base LOCAL de pruebas
 * (docker). Nunca contra produccion. Ver docs/reserva-online-runbook.md.
 *
 *   ALLOW_PGSQL_CONCURRENCY_TEST=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 \
 *   DB_DATABASE=nails_test php artisan test --filter=ReservaHoldsConcurrenciaPgsqlTest
 *
 * No usa RefreshDatabase (los workers son procesos aparte y necesitan datos
 * commiteados): crea filas propias y las borra al terminar.
 */
#[\PHPUnit\Framework\Attributes\Group('pgsql')]
class ReservaHoldsConcurrenciaPgsqlTest extends TestCase
{
    private const WORKERS = 8;
    private ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('ALLOW_PGSQL_CONCURRENCY_TEST') !== '1' || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: requiere ALLOW_PGSQL_CONCURRENCY_TEST=1 y DB_CONNECTION=pgsql (base LOCAL).');
        }
        $host = (string) config('database.connections.pgsql.host');
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1', 'host.docker.internal', 'postgres', 'db'], true)) {
            $this->markTestSkipped("Host '{$host}' no parece una base local de pruebas: no se ejecuta por seguridad.");
        }
        if (! Schema::hasTable('reservas_web') || ! Schema::hasColumn('reservas_web', 'public_token')) {
            $this->markTestSkipped('La base local no tiene aplicada la migracion de holds (php artisan migrate).');
        }
    }

    protected function tearDown(): void
    {
        if ($this->user) {
            ReservaWeb::where('user_id', $this->user->id)->delete();
            $this->user->delete();
        }
        parent::tearDown();
    }

    public function test_n_workers_paralelos_sobre_el_mismo_horario_dan_exactamente_un_ganador(): void
    {
        $this->user = User::factory()->create(['is_exempt' => true]);
        $prof = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'activo' => true]);
        $servicio = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'S', 'duracion_minutos' => 60, 'precio' => 1, 'activo' => true]);
        $prof->servicios()->attach($servicio->id);
        SlotDisponible::create(['user_id' => $this->user->id, 'profesional_id' => $prof->id, 'hora' => '10:00', 'activo' => true]);
        $fecha = now()->addDays(10)->format('Y-m-d');
        $largada = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'largada-' . uniqid();

        $procesos = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $p = new Process([
                PHP_BINARY, base_path('tests/Support/hold_worker.php'),
                (string) $this->user->id, (string) $servicio->id, (string) $prof->id, $fecha, '10:00',
                'device-concurrencia-' . str_pad((string) $i, 24, '0', STR_PAD_LEFT), $largada,
            ], base_path(), array_merge($_ENV, ['DB_CONNECTION' => 'pgsql']));
            $p->start();
            $procesos[] = $p;
        }

        usleep(1_500_000); // tiempo para que todos los workers arranquen y queden esperando
        touch($largada);
        foreach ($procesos as $p) {
            $p->wait();
        }
        @unlink($largada);

        $resultados = array_map(fn (Process $p) => json_decode(trim($p->getOutput()), true), $procesos);
        $ganadores = array_filter($resultados, fn ($r) => $r['ok'] ?? false);
        $codigos = array_column(array_filter($resultados, fn ($r) => ! ($r['ok'] ?? false)), 'code');

        $this->assertCount(1, $ganadores, 'debe haber exactamente un ganador: ' . json_encode($resultados));
        $this->assertSame(array_fill(0, self::WORKERS - 1, 'slot_taken'), array_values($codigos));
        $this->assertSame(1, ReservaWeb::where('profesional_id', $prof->id)->where('estado', 'held')->count());
    }
}

<?php

namespace Tests\Feature\Pro;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * Cost boundaries of the filtered list: the (profesional_id, fecha_hora) index
 * that backs ranges and the number of queries when every filter is combined.
 * The index is performance only: nothing else depends on it, and dropping it
 * (migrate:rollback --step=1) must be safe.
 */
class ProTurnosFronterasTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private const INDICE = 'turnos_profesional_id_fecha_hora_index';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function migracion(): Migration
    {
        $archivos = glob(database_path('migrations/*_add_turnos_profesional_fecha_index.php'));
        $this->assertCount(1, $archivos, 'exactly one migration adds the turnos index');

        return require $archivos[0];
    }

    // ── index presence and reversibility ─────────────────────────

    public function test_the_turnos_profesional_fecha_index_exists_after_migrating(): void
    {
        $this->assertTrue(Schema::hasIndex('turnos', self::INDICE));
        $this->assertTrue(Schema::hasIndex('turnos', ['profesional_id', 'fecha_hora']));
        // Additive: the single-column index that already existed is still there.
        $this->assertTrue(Schema::hasIndex('turnos', 'turnos_profesional_id_index'));
    }

    public function test_the_migration_is_reversible_and_re_runnable(): void
    {
        $migracion = $this->migracion();

        $migracion->down();
        $this->assertFalse(Schema::hasIndex('turnos', self::INDICE));
        $this->assertTrue(Schema::hasIndex('turnos', 'turnos_profesional_id_index'));

        $migracion->up();
        $this->assertTrue(Schema::hasIndex('turnos', self::INDICE));

        // Re-running up() and down() on an already migrated / rolled back schema must not fail.
        $migracion->up();
        $this->assertTrue(Schema::hasIndex('turnos', self::INDICE));
        $migracion->down();
        $migracion->down();
        $this->assertFalse(Schema::hasIndex('turnos', self::INDICE));
    }

    public function test_the_migration_runs_outside_a_transaction_for_concurrent_index_builds(): void
    {
        $this->assertFalse($this->migracion()->withinTransaction);
    }

    public function test_on_postgres_the_index_is_built_and_dropped_concurrently_with_if_guards(): void
    {
        $sentencias = [];
        DB::partialMock()
            ->shouldReceive('getDriverName')->andReturn('pgsql')
            ->shouldReceive('statement')->andReturnUsing(function (string $sql) use (&$sentencias) {
                $sentencias[] = $sql;

                return true;
            });

        $migracion = $this->migracion();
        $migracion->up();
        $migracion->down();

        $this->assertSame([
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDICE.' ON turnos (profesional_id, fecha_hora)',
            'DROP INDEX CONCURRENTLY IF EXISTS '.self::INDICE,
        ], $sentencias);
    }

    public function test_the_range_query_of_one_professional_can_use_the_new_index(): void
    {
        $plan = collect(DB::select(
            'EXPLAIN QUERY PLAN SELECT id FROM turnos WHERE profesional_id = ? AND fecha_hora >= ? AND fecha_hora < ?',
            [1, '2026-10-01 00:00:00', '2026-11-01 00:00:00'],
        ))->pluck('detail')->implode(' | ');

        $this->assertStringContainsString(self::INDICE, $plan);
    }

    // ── combined filters: constant number of queries ─────────────

    /** @return array{0: int, 1: int} [queries for the request, rows returned] */
    private function consultasConTodosLosFiltros(int $cantidad, string $email): array
    {
        Carbon::setTestNow('2026-10-17 12:00:00');
        $negocio = $this->crearNegocioPortal(['email' => $email]);
        $cuenta = $this->crearCuentaPortal($email);
        $vinculo = $this->vincularPortal($cuenta, $negocio['ana']);
        $token = $this->tokenPara($cuenta);
        $capping = $this->crearServicioPortal($negocio['user'], 'Capping');
        $pedicura = $this->crearServicioPortal($negocio['user'], 'Pedicura');
        $negocio['ana']->servicios()->attach([$capping->id, $pedicura->id]);

        for ($i = 0; $i < $cantidad; $i++) {
            $cliente = $this->crearClientePortal($negocio['user'], 'Maria'.$i, 'Lopez', sprintf('+5491155%06d', $i));
            $turno = $this->crearTurnoPortal($negocio['ana'], '2026-10-15 '.sprintf('%02d:%02d:00', intdiv($i, 60), $i % 60), ['cliente_id' => $cliente->id]);
            $turno->servicios()->attach([$capping->id => ['precio' => 100], $pedicura->id => ['precio' => 200]]);
        }

        $consultar = fn () => $this->withToken($token)->getJson(
            '/api/pro/turnos?'.http_build_query([
                'desde' => '2026-10-10', 'hasta' => '2026-10-20', 'buscar' => 'maria lopez', 'servicio_id' => $capping->id,
            ]),
            ['X-Vinculo-Id' => (string) $vinculo->id],
        );

        // Sanctum writes last_used_at only the first time a token is used: warm it up.
        $consultar()->assertOk();
        $this->app['auth']->forgetGuards();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $respuesta = $consultar()->assertOk();
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$consultas, count($respuesta->json('data'))];
    }

    public function test_the_number_of_queries_with_every_filter_does_not_grow_with_the_rows(): void
    {
        [$pocas, $filasPocas] = $this->consultasConTodosLosFiltros(3, 'pocas@x.com');
        $this->app['auth']->forgetGuards();
        [$muchas, $filasMuchas] = $this->consultasConTodosLosFiltros(40, 'muchas@x.com');

        $this->assertSame(3, $filasPocas);
        $this->assertSame(40, $filasMuchas);
        $this->assertSame($pocas, $muchas);
    }
}

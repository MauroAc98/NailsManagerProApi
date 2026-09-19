<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\User;
use App\Services\Reservas\SlotLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SlotLockTest extends TestCase
{
    use RefreshDatabase;

    private function profesional(): Profesional
    {
        $user = User::factory()->create();

        return Profesional::create(['user_id' => $user->id, 'nombre' => 'Ana', 'activo' => true]);
    }

    public function test_devuelve_el_resultado_y_corre_dentro_de_una_transaccion(): void
    {
        $prof = $this->profesional();
        $base = DB::transactionLevel();

        $nivel = (new SlotLock())->conLock($prof->id, fn () => DB::transactionLevel());

        $this->assertGreaterThan($base, $nivel);
        $this->assertSame($base, DB::transactionLevel());
        $this->assertSame('ok', (new SlotLock())->conLock($prof->id, fn () => 'ok'));
    }

    public function test_una_excepcion_hace_rollback_y_se_propaga(): void
    {
        $prof = $this->profesional();

        try {
            (new SlotLock())->conLock($prof->id, function () use ($prof) {
                DB::table('profesionales')->where('id', $prof->id)->update(['nombre' => 'Cambiada']);
                throw new \RuntimeException('boom');
            });
            $this->fail('debio propagar');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame('Ana', $prof->fresh()->nombre);
    }

    public function test_fuera_de_pgsql_toma_lock_de_fila_sobre_la_profesional(): void
    {
        $prof = $this->profesional();
        $sqls = [];
        DB::listen(function ($q) use (&$sqls) {
            $sqls[] = [$q->sql, $q->bindings];
        });

        (new SlotLock('sqlite'))->conLock($prof->id, fn () => null);

        $lock = collect($sqls)->first(fn ($q) => str_contains($q[0], 'from "profesionales"') && in_array($prof->id, $q[1]));
        $this->assertNotNull($lock, 'debio consultar la fila de la profesional dentro del lock');
    }

    public function test_en_pgsql_emite_pg_advisory_xact_lock_con_namespace_e_id(): void
    {
        $prof = $this->profesional();
        $emitidas = [];
        $lock = new class('pgsql', $emitidas) extends SlotLock {
            public function __construct(string $driver, public array &$emitidas)
            {
                parent::__construct($driver);
            }

            protected function ejecutar(string $sql, array $bindings): void
            {
                $this->emitidas[] = [$sql, $bindings, \Illuminate\Support\Facades\DB::transactionLevel()];
            }
        };

        $this->assertSame('ok', $lock->conLock($prof->id, fn () => 'ok'));

        $this->assertCount(1, $emitidas);
        $this->assertStringContainsString('pg_advisory_xact_lock', $emitidas[0][0]);
        $this->assertSame([SlotLock::NAMESPACE_LOCK, $prof->id], $emitidas[0][1]);
        $this->assertGreaterThan(0, $emitidas[0][2], 'el lock se toma dentro de la transaccion');
    }
}

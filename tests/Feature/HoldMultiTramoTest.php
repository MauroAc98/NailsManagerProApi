<?php

namespace Tests\Feature;

use App\Exceptions\ReservaPublicaException;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\User;
use App\Services\Reservas\HoldService;
use App\Services\Reservas\ReputacionService;
use App\Services\Reservas\SlotLock;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

/**
 * combo-multi-profesional, PR 3b: HoldService con planes (promo componentizada
 * y/o 2+ grupos sueltos multi-profesional), locks ordenados (SlotLock::conLocks)
 * y `modo` obligatorio. El camino legacy (un unico grupo, sin promo) sigue
 * cubierto -sin cambios de comportamiento- por HoldServiceRetenerTest (Rule L).
 */
class HoldMultiTramoTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    private const FECHA = '2099-06-11';

    private User $user;

    private Profesional $ana;

    private Profesional $laura;

    private Servicio $softgel;

    private Servicio $semis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->crearSalon();
        $this->ana = $this->crearProfesional($this->user, 'Ana');
        $this->laura = $this->crearProfesional($this->user, 'Laura');
        $this->softgel = $this->crearServicio($this->user, 'Softgel', 60, true, $this->ana);
        $this->semis = $this->crearServicio($this->user, 'Semis', 45, true, $this->laura);
        foreach (['10:00', '10:30', '11:00', '11:30', '12:00'] as $h) {
            $this->crearSlot($this->user, $this->ana, $h);
            $this->crearSlot($this->user, $this->laura, $h);
        }
    }

    private function ahora(): Carbon
    {
        return Carbon::parse('2099-06-01 09:00:00');
    }

    private function svc(): HoldService
    {
        return app(HoldService::class);
    }

    private function asignacion(?Profesional $p, Servicio $s): array
    {
        return ['servicio_ids' => [$s->id], 'profesional_id' => $p?->id];
    }

    private function retener(array $asignaciones, ?string $modo, string $hora = '10:00', string $device = 'dev-1', string $key = 'key-1')
    {
        return $this->svc()->retener(
            $this->user, $asignaciones, $modo, self::FECHA, $hora,
            (new ReputacionService())->hashDevice($device), $key, $this->ahora(),
        );
    }

    private function codigo(callable $fn): string
    {
        try {
            $fn();
        } catch (ReservaPublicaException $e) {
            return $e->codigo;
        }

        return 'no-exception';
    }

    public function test_secuencial_crea_un_hold_con_tramos_y_bloquea_a_ambas_profesionales(): void
    {
        $r = $this->retener([$this->asignacion($this->ana, $this->softgel), $this->asignacion($this->laura, $this->semis)], 'secuencia');

        $h = $r->reserva->fresh();
        $this->assertSame($this->ana->id, $h->profesional_id); // ancla = profesional del primer tramo
        $this->assertSame('secuencia', $h->tramos_modo);
        $this->assertSame(105, $h->duracion_total_minutos); // 60 + 45
        $this->assertCount(2, $h->tramos);
        $this->assertSame(0, $h->tramos[0]['offset_minutos']);
        $this->assertSame($this->laura->id, $h->tramos[1]['profesional_id']);
        $this->assertSame(60, $h->tramos[1]['offset_minutos']);

        // Laura queda ocupada 11:00-11:45: otro dispositivo no puede retenerla a esa hora.
        $this->assertSame('slot_taken', $this->codigo(fn () => $this->retener(
            [$this->asignacion($this->laura, $this->semis)], null, '11:00', 'dev-2', 'k2',
        )));
        $this->assertSame(1, ReservaWeb::count());
    }

    public function test_paralelo_ambas_profesionales_arrancan_a_la_misma_hora(): void
    {
        $this->user->update(['atiende_en_paralelo' => true]);

        $r = $this->retener([$this->asignacion($this->ana, $this->softgel), $this->asignacion($this->laura, $this->semis)], 'paralelo');

        $h = $r->reserva->fresh();
        $this->assertSame('paralelo', $h->tramos_modo);
        $this->assertSame(60, $h->duracion_total_minutos); // max(60, 45)
        $this->assertSame(0, $h->tramos[0]['offset_minutos']);
        $this->assertSame(0, $h->tramos[1]['offset_minutos']);
    }

    public function test_falta_el_modo_con_2_grupos_es_validation_y_no_crea_fila(): void
    {
        $this->assertSame('validation', $this->codigo(fn () => $this->retener(
            [$this->asignacion($this->ana, $this->softgel), $this->asignacion($this->laura, $this->semis)], null,
        )));
        $this->assertSame(0, ReservaWeb::count());
    }

    public function test_modo_que_ya_no_esta_disponible_es_slot_taken_409(): void
    {
        // El estudio no atiende en paralelo (default): el unico plan calculado
        // es secuencia. Pedir "paralelo" es un modo vencido/invalido.
        $this->assertSame('slot_taken', $this->codigo(fn () => $this->retener(
            [$this->asignacion($this->ana, $this->softgel), $this->asignacion($this->laura, $this->semis)], 'paralelo',
        )));
        $this->assertSame(0, ReservaWeb::count());
    }

    public function test_una_profesional_ya_ocupada_en_su_propio_tramo_es_slot_taken_409(): void
    {
        $this->retener([$this->asignacion($this->laura, $this->semis)], null, '11:00', 'dev-x', 'kx');

        // Secuencial: Laura arrancaria justo a las 11:00 (Ana 10:00-11:00), que ya esta held.
        $this->assertSame('slot_taken', $this->codigo(fn () => $this->retener(
            [$this->asignacion($this->ana, $this->softgel), $this->asignacion($this->laura, $this->semis)], 'secuencia', '10:00', 'dev-2', 'k2',
        )));
        $this->assertSame(1, ReservaWeb::count());
    }

    public function test_hold_multi_tramo_le_pasa_a_conlocks_todas_las_profesionales_del_plan(): void
    {
        $spy = new class('sqlite') extends SlotLock {
            public array $vistos = [];

            public function conLocks(array $profesionalIds, Closure $fn): mixed
            {
                $this->vistos = $profesionalIds;

                return parent::conLocks($profesionalIds, $fn);
            }
        };
        $this->app->instance(SlotLock::class, $spy);

        // Laura (mayor id) elegida ANTES que Ana en la lista de asignaciones:
        // conLocks() (ya probado en SlotLockTest) es quien ordena, no el caller.
        $this->retener([$this->asignacion($this->laura, $this->semis), $this->asignacion($this->ana, $this->softgel)], 'secuencia');

        sort($spy->vistos);
        $this->assertSame([$this->ana->id, $this->laura->id], $spy->vistos);
    }
}

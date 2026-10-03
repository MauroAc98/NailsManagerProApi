<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\ServicioComponente;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\TurnoGrupo;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Contract\AdminContractTestCase;

/**
 * combo-multi-profesional, PR 6b: POST /turnos con una promo con componentes
 * crea el grupo completo (flujo de la duena: sin regla de slots, pero cada
 * tramo respeta rango de atencion, choques y holds de SU profesional). Una
 * reserva sin promo con componentes queda exactamente como siempre (Rule L).
 */
class CrearGrupoManualTest extends AdminContractTestCase
{
    private Profesional $laura;

    private Servicio $promo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->laura = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Laura', 'activo' => true]);
        foreach (['09:00:00', '18:00:00'] as $hora) {
            SlotDisponible::create(['user_id' => $this->user->id, 'profesional_id' => $this->laura->id, 'hora' => $hora, 'activo' => true]);
        }
        $softgel = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Softgel', 'duracion_minutos' => 60, 'precio' => 13000, 'activo' => true]);
        $semis = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Semis', 'duracion_minutos' => 45, 'precio' => 9000, 'activo' => true]);
        $this->ana->servicios()->attach($softgel->id);
        $this->laura->servicios()->attach($semis->id);
        $this->promo = Servicio::create([
            'user_id' => $this->user->id, 'nombre' => 'Combo', 'duracion_minutos' => 105, 'precio' => 18000,
            'activo' => true, 'es_promo' => true, 'modo_promo' => 'secuencia',
        ]);
        ServicioComponente::create(['servicio_id' => $this->promo->id, 'componente_servicio_id' => $softgel->id, 'profesional_id' => $this->ana->id, 'orden' => 1]);
        ServicioComponente::create(['servicio_id' => $this->promo->id, 'componente_servicio_id' => $semis->id, 'profesional_id' => $this->laura->id, 'orden' => 2]);
    }

    private function crear(string $fechaHora, array $extra = [])
    {
        return $this->admin()->postJson('/api/turnos', [
            'cliente_id' => $this->cliente->id, 'servicio_ids' => [$this->promo->id], 'fecha_hora' => $fechaHora, 'notas' => 'n',
        ] + $extra);
    }

    public function test_crea_un_grupo_con_un_turno_por_tramo_a_cualquier_hora_y_precios_prorrateados(): void
    {
        $resp = $this->crear('2099-06-10 14:10:00')->assertCreated(); // 14:10 / 15:10 no son slots: la duena no tiene regla de slots

        $grupo = TurnoGrupo::firstOrFail();
        $this->assertSame('secuencia', $grupo->modo);
        $this->assertSame($this->promo->id, $grupo->promo_servicio_id);
        $this->assertEquals(18000, $grupo->precio_promo);
        $turnos = Turno::where('grupo_id', $grupo->id)->orderBy('id')->get();
        $this->assertSame([$this->ana->id, $this->laura->id], $turnos->pluck('profesional_id')->all());
        $this->assertSame(['2099-06-10 14:10:00', '2099-06-10 15:10:00'], $turnos->map(fn ($t) => $t->getRawOriginal('fecha_hora'))->all());
        $this->assertSame([60, 45], $turnos->pluck('duracion_total_minutos')->all());
        $this->assertSame([10636, 7364], $turnos->map(fn ($t) => (int) $t->servicios()->first()->pivot->precio_sugerido)->all());
        $this->assertSame($turnos[0]->id, $resp->json('id'));
        $this->assertSame($grupo->id, $resp->json('grupo_id'));
    }

    public function test_un_precio_de_promo_manual_pisa_el_de_la_promo(): void
    {
        $this->crear('2099-06-10 10:00:00', ['precio_promo' => 11000])->assertCreated();

        $this->assertEquals(11000, TurnoGrupo::firstOrFail()->precio_promo);
        $this->assertSame(11000, Turno::where('grupo_id', TurnoGrupo::first()->id)->get()->sum(fn ($t) => (int) $t->servicios()->first()->pivot->precio_sugerido));
    }

    public function test_si_una_profesional_esta_ocupada_en_su_tramo_no_se_crea_nada_y_se_la_nombra(): void
    {
        Turno::create([
            'user_id' => $this->user->id, 'profesional_id' => $this->laura->id, 'cliente_id' => $this->cliente->id,
            'fecha_hora' => '2099-06-10 15:30:00', 'duracion_total_minutos' => 30, 'estado' => 'confirmado', 'origen' => 'app',
        ]);

        $this->crear('2099-06-10 14:10:00')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Laura'));

        $this->assertSame(0, TurnoGrupo::count());
        $this->assertSame(1, Turno::count());
    }

    public function test_si_el_tramo_de_una_profesional_cae_fuera_de_su_horario_se_la_nombra(): void
    {
        $this->crear('2099-06-10 17:30:00')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Laura'));

        $this->assertSame(0, Turno::count());
    }

    public function test_manda_una_sola_confirmacion_para_todo_el_grupo(): void
    {
        $this->crear('2099-06-10 10:00:00')->assertCreated();

        Queue::assertPushed(EnviarMensajeConfirmacion::class, 1);
    }

    public function test_un_servicio_comun_sigue_creandose_como_siempre(): void
    {
        $json = $this->admin()->postJson('/api/turnos', [
            'cliente_id' => $this->cliente->id, 'servicio_ids' => [$this->servicio->id], 'fecha_hora' => '2099-06-10 10:00:00',
        ])->assertCreated()->json();

        $this->assertArrayNotHasKey('grupo', $json);
        $this->assertNull($json['grupo_id'] ?? null);
        $this->assertSame(0, TurnoGrupo::count());
        $this->assertSame(1, Turno::count());
    }
}

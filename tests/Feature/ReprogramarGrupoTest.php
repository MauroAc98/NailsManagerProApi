<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\TurnoGrupo;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Contract\AdminContractTestCase;

/**
 * combo-multi-profesional, PR 6d: POST /turnos/grupos/{grupo}/reprogramar mueve
 * el grupo entero (todo o nada). Flujo de la duena: el inicio es libre (sin
 * regla de slots) pero cada profesional debe estar libre en SU intervalo. Se
 * conservan los offsets entre tramos, no se re-precia ni se avisa por WhatsApp.
 */
class ReprogramarGrupoTest extends AdminContractTestCase
{
    private Profesional $laura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->laura = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Laura', 'activo' => true]);
        foreach (['09:00:00', '18:00:00'] as $hora) {
            SlotDisponible::create(['user_id' => $this->user->id, 'profesional_id' => $this->laura->id, 'hora' => $hora, 'activo' => true]);
        }
    }

    /** Ana 10:00-10:30 y Laura (offset en minutos) en el mismo grupo. @return array{0: Turno, 1: Turno} */
    private function grupo(int $offsetLaura = 60, string $modo = 'secuencia'): array
    {
        $g = TurnoGrupo::create(['modo' => $modo]);
        $a = $this->crearTurno('confirmado', '2099-06-10 10:00:00');
        $b = $this->crearTurno('confirmado', Carbon::parse('2099-06-10 10:00:00')->addMinutes($offsetLaura)->format('Y-m-d H:i:s'));
        $a->update(['grupo_id' => $g->id]);
        $b->update(['grupo_id' => $g->id, 'profesional_id' => $this->laura->id]);
        $a->servicios()->updateExistingPivot($this->servicio->id, ['precio_sugerido' => 700]);

        return [$a, $b];
    }

    private function reprogramar(Turno $t, string $fechaHora)
    {
        return $this->admin()->postJson("/api/turnos/grupos/{$t->grupo_id}/reprogramar", ['fecha_hora' => $fechaHora]);
    }

    private function hora(Turno $t): string
    {
        return $t->fresh()->getRawOriginal('fecha_hora');
    }

    public function test_secuencial_mueve_a_todos_conservando_el_desfasaje_y_responde_el_grupo(): void
    {
        [$a, $b] = $this->grupo();

        $json = $this->reprogramar($a, '2099-06-12 14:10:00')->assertOk()->json();

        $this->assertSame('2099-06-12 14:10:00', $this->hora($a));
        $this->assertSame('2099-06-12 15:10:00', $this->hora($b)); // 14:10 no es slot: la duena no tiene esa regla
        $this->assertSame($a->grupo_id, $json['grupo']['id']);
        $this->assertSame(['2099-06-12T14:10:00', '2099-06-12T15:10:00'], array_column($json['grupo']['tramos'], 'fecha_hora'));
        $this->assertSame([$a->id, $b->id], $json['movidos']);
        $this->assertEquals(700, $a->servicios()->first()->pivot->precio_sugerido);
    }

    public function test_en_paralelo_ambos_siguen_empezando_a_la_vez(): void
    {
        [$a, $b] = $this->grupo(0, 'paralelo');

        $this->reprogramar($b, '2099-06-12 13:00:00')->assertOk();

        $this->assertSame('2099-06-12 13:00:00', $this->hora($a));
        $this->assertSame('2099-06-12 13:00:00', $this->hora($b));
    }

    public function test_los_turnos_del_propio_grupo_no_cuentan_como_choque(): void
    {
        [$a, $b] = $this->grupo(30);

        $this->reprogramar($a, '2099-06-10 10:15:00')->assertOk(); // pisa su propio horario anterior

        $this->assertSame('2099-06-10 10:45:00', $this->hora($b));
    }

    public function test_si_una_profesional_esta_ocupada_no_se_mueve_nada_y_se_la_nombra(): void
    {
        [$a, $b] = $this->grupo();
        Turno::create([
            'user_id' => $this->user->id, 'profesional_id' => $this->laura->id, 'cliente_id' => $this->cliente->id,
            'fecha_hora' => '2099-06-12 15:00:00', 'duracion_total_minutos' => 30, 'estado' => 'confirmado', 'origen' => 'app',
        ]);

        $this->reprogramar($a, '2099-06-12 14:00:00')->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Laura'));

        $this->assertSame('2099-06-10 10:00:00', $this->hora($a));
        $this->assertSame('2099-06-10 11:00:00', $this->hora($b));
    }

    public function test_fuera_del_horario_de_atencion_de_una_profesional_se_la_nombra(): void
    {
        [$a] = $this->grupo();

        $this->reprogramar($a, '2099-06-12 17:30:00')->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Laura'));

        $this->assertSame('2099-06-10 10:00:00', $this->hora($a));
    }

    public function test_un_hold_vivo_de_reserva_online_bloquea_con_slot_held(): void
    {
        Carbon::setTestNow('2099-06-01 09:00:00');
        [$a] = $this->grupo();
        ReservaWeb::create([
            'user_id' => $this->user->id, 'profesional_id' => $this->laura->id, 'public_token' => ReservaWeb::generarToken(),
            'servicio_ids' => [$this->servicio->id], 'estado' => 'held', 'fecha' => '2099-06-12', 'slot_hora' => '15:00:00',
            'duracion_total_minutos' => 30, 'expira_en' => Carbon::now()->timestamp + 600,
        ]);

        $this->reprogramar($a, '2099-06-12 14:00:00')->assertStatus(422)->assertJsonPath('code', 'slot_held');
        Carbon::setTestNow();
    }

    public function test_si_el_primer_tramo_esta_cancelado_el_primero_que_queda_es_el_ancla_y_el_cancelado_no_se_toca(): void
    {
        [$a, $b] = $this->grupo();
        $a->update(['estado' => 'cancelado']);

        $this->reprogramar($b, '2099-06-12 14:00:00')->assertOk()->assertJsonPath('movidos', [$b->id]);

        $this->assertSame('2099-06-12 14:00:00', $this->hora($b));
        $this->assertSame('2099-06-10 10:00:00', $this->hora($a));
    }

    public function test_si_un_tramo_ya_se_atendio_se_rechaza_todo(): void
    {
        [$a, $b] = $this->grupo();
        $b->update(['estado' => 'completado']);

        $this->reprogramar($a, '2099-06-12 14:00:00')->assertStatus(422)->assertJsonPath('code', 'grupo_en_curso');

        $this->assertSame('2099-06-10 10:00:00', $this->hora($a));
    }

    public function test_limpia_el_recordatorio_de_los_tramos_movidos_y_no_manda_whatsapp(): void
    {
        [$a, $b] = $this->grupo();
        WhatsappMensaje::create(['user_id' => $this->user->id, 'turno_id' => $b->id, 'numero' => 'x', 'mensaje' => '', 'tipo' => 'recordatorio', 'status' => 'manual']);
        WhatsappMensaje::create(['user_id' => $this->user->id, 'turno_id' => $a->id, 'numero' => 'x', 'mensaje' => '', 'tipo' => 'confirmacion', 'status' => 'delivered']);

        $this->reprogramar($a, '2099-06-12 14:00:00')->assertOk();

        $this->assertSame(0, WhatsappMensaje::where('tipo', 'recordatorio')->count());
        $this->assertSame(1, WhatsappMensaje::where('tipo', 'confirmacion')->count());
        Queue::assertNothingPushed();
    }

    public function test_un_grupo_de_otro_negocio_es_404(): void
    {
        [$a] = $this->grupo();
        $otro = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($otro->fresh(), 'sanctum')
            ->postJson("/api/turnos/grupos/{$a->grupo_id}/reprogramar", ['fecha_hora' => '2099-06-12 14:00:00'])
            ->assertNotFound();
    }

    public function test_editar_un_turno_comun_con_put_sigue_igual_y_no_toca_a_otros(): void
    {
        $turno = $this->crearTurno('confirmado', '2099-06-10 10:00:00');
        $otro = $this->crearTurno('confirmado', '2099-06-11 12:00:00');

        $json = $this->admin()->putJson("/api/turnos/{$turno->id}", [
            'cliente_id' => $this->cliente->id, 'servicio_ids' => [$this->servicio->id], 'fecha_hora' => '2099-06-10 11:00:00',
        ])->assertOk()->json();

        $this->assertArrayNotHasKey('grupo', $json);
        $this->assertSame('2099-06-10 11:00:00', $this->hora($turno));
        $this->assertSame('2099-06-11 12:00:00', $this->hora($otro));
    }
}

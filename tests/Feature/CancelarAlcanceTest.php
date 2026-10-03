<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\Turno;
use App\Models\TurnoGrupo;
use App\Models\User;
use Tests\Feature\Contract\AdminContractTestCase;

/**
 * combo-multi-profesional, PR 5a: DELETE /turnos/{id} con `alcance`
 * (tramo = default, grupo = todos los tramos del grupo, todo o nada).
 * Sin re-precio ni reembolso automatico.
 */
class CancelarAlcanceTest extends AdminContractTestCase
{
    /** @return array{0: Turno, 1: Turno} */
    private function crearGrupo(): array
    {
        $laura = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Laura', 'activo' => true]);
        $grupo = TurnoGrupo::create(['modo' => 'secuencia']);
        $a = $this->crearTurno('confirmado', '2099-06-10 10:00:00');
        $b = $this->crearTurno('confirmado', '2099-06-10 11:00:00');
        $a->update(['grupo_id' => $grupo->id]);
        $b->update(['grupo_id' => $grupo->id, 'profesional_id' => $laura->id]);
        $a->servicios()->updateExistingPivot($this->servicio->id, ['precio' => 1000, 'precio_sugerido' => 1000]);

        return [$a, $b];
    }

    private function cancelar(Turno $t, array $extra = [])
    {
        return $this->admin()->deleteJson("/api/turnos/{$t->id}", ['motivo_cancelacion' => 'no puede venir'] + $extra);
    }

    public function test_sin_alcance_un_turno_sin_grupo_responde_exactamente_como_siempre(): void
    {
        $turno = $this->crearTurno();

        $this->cancelar($turno)->assertOk()->assertExactJson(['message' => 'Turno cancelado correctamente.']);
        $this->assertSame('cancelado', $turno->fresh()->estado);
    }

    public function test_por_defecto_cancela_solo_el_tramo_y_el_hermano_queda_intacto(): void
    {
        [$a, $b] = $this->crearGrupo();

        $this->cancelar($b)->assertOk()->assertExactJson(['message' => 'Turno cancelado correctamente.']);

        $this->assertSame('cancelado', $b->fresh()->estado);
        $this->assertSame('confirmado', $a->fresh()->estado);
        $this->assertEquals(1000, $a->servicios()->first()->pivot->precio);
    }

    public function test_alcance_grupo_cancela_todos_los_tramos_con_el_mismo_motivo(): void
    {
        [$a, $b] = $this->crearGrupo();

        $this->cancelar($a, ['alcance' => 'grupo'])->assertOk()
            ->assertExactJson(['message' => 'Turno cancelado correctamente.', 'cancelados' => [$a->id, $b->id]]);

        foreach ([$a, $b] as $t) {
            $t = $t->fresh();
            $this->assertSame('cancelado', $t->estado);
            $this->assertSame('no puede venir', $t->motivo_cancelacion);
        }
    }

    public function test_alcance_grupo_no_toca_a_los_tramos_ya_cancelados(): void
    {
        [$a, $b] = $this->crearGrupo();
        $a->update(['estado' => 'cancelado', 'motivo_cancelacion' => 'previo']);

        $this->cancelar($b, ['alcance' => 'grupo'])->assertOk()->assertJsonPath('cancelados', [$b->id]);

        $this->assertSame('previo', $a->fresh()->motivo_cancelacion);
    }

    public function test_alcance_grupo_nunca_toca_un_tramo_completado_y_cancela_los_demas(): void
    {
        [$a, $b] = $this->crearGrupo();
        $b->update(['estado' => 'completado']);

        $this->cancelar($a, ['alcance' => 'grupo'])->assertOk()->assertJsonPath('cancelados', [$a->id]);

        $this->assertSame('cancelado', $a->fresh()->estado);
        $this->assertSame('completado', $b->fresh()->estado);
        $this->assertNull($b->fresh()->motivo_cancelacion);
    }

    public function test_alcance_grupo_cancela_tambien_un_tramo_cuyo_horario_ya_paso_sin_finalizar(): void
    {
        [$a, $b] = $this->crearGrupo();
        $b->update(['fecha_hora' => '2020-01-01 10:00:00']);

        $this->cancelar($a, ['alcance' => 'grupo'])->assertOk()->assertJsonPath('cancelados', [$a->id, $b->id]);

        $this->assertSame('cancelado', $b->fresh()->estado);
    }

    public function test_alcance_grupo_desde_un_tramo_pasado_sin_finalizar_tambien_funciona(): void
    {
        [$a, $b] = $this->crearGrupo();
        $a->update(['fecha_hora' => '2020-01-01 10:00:00']);

        $this->cancelar($a, ['alcance' => 'grupo'])->assertOk()->assertJsonPath('cancelados', [$a->id, $b->id]);
    }

    public function test_alcance_grupo_sin_tramos_pendientes_es_422_y_no_cambia_nada(): void
    {
        [$a, $b] = $this->crearGrupo();
        $a->update(['estado' => 'cancelado', 'motivo_cancelacion' => 'previo']);
        $b->update(['estado' => 'completado']);

        $this->cancelar($b, ['alcance' => 'grupo'])->assertStatus(422)->assertJsonPath('code', 'grupo_sin_pendientes');

        $this->assertSame('previo', $a->fresh()->motivo_cancelacion);
        $this->assertSame('completado', $b->fresh()->estado);
    }

    public function test_alcance_grupo_en_un_turno_sin_grupo_cancela_solo_ese_turno(): void
    {
        $turno = $this->crearTurno();

        $this->cancelar($turno, ['alcance' => 'grupo'])->assertOk()->assertExactJson(['message' => 'Turno cancelado correctamente.']);
    }

    public function test_un_alcance_invalido_es_422(): void
    {
        $this->cancelar($this->crearTurno(), ['alcance' => 'otro'])->assertStatus(422);
    }

    public function test_no_se_cancelan_tramos_de_otro_negocio(): void
    {
        [$a] = $this->crearGrupo();
        $otro = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($otro->fresh(), 'sanctum')
            ->deleteJson("/api/turnos/{$a->id}", ['motivo_cancelacion' => 'x', 'alcance' => 'grupo'])
            ->assertNotFound();
    }
}

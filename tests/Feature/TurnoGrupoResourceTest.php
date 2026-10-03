<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\Turno;
use App\Models\TurnoGrupo;
use Tests\Feature\Contract\AdminContractTestCase;

/**
 * combo-multi-profesional, PR 5a: GET /turnos and /turnos/{id} suman `grupo`
 * (id, modo, tramos) SOLO a los turnos agrupados. Un turno legacy conserva su
 * forma exacta (Rule L; ademas lo fijan AdminTurnosShapeTest/AssertsContractShapeTest).
 */
class TurnoGrupoResourceTest extends AdminContractTestCase
{
    private Profesional $laura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->laura = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Laura', 'activo' => true]);
    }

    /** @return array{0: Turno, 1: Turno} */
    private function crearGrupo(): array
    {
        $grupo = TurnoGrupo::create(['modo' => 'secuencia']);
        $a = $this->crearTurno('confirmado', '2099-06-10 10:00:00');
        $b = $this->crearTurno('confirmado', '2099-06-10 11:00:00');
        $a->update(['grupo_id' => $grupo->id]);
        $b->update(['grupo_id' => $grupo->id, 'profesional_id' => $this->laura->id, 'duracion_total_minutos' => 45]);

        return [$a, $b];
    }

    public function test_un_turno_sin_grupo_no_trae_la_clave_grupo(): void
    {
        $turno = $this->crearTurno();

        $lista = $this->admin()->getJson('/api/turnos')->assertOk()->json();
        $uno = $this->admin()->getJson("/api/turnos/{$turno->id}")->assertOk()->json();

        $this->assertArrayNotHasKey('grupo', $lista[0]);
        $this->assertArrayNotHasKey('grupo', $uno);
        $this->assertNull($uno['grupo_id']);
    }

    public function test_un_turno_agrupado_trae_el_grupo_con_todos_sus_tramos_en_el_listado_y_el_detalle(): void
    {
        [$a, $b] = $this->crearGrupo();
        $esperado = [
            'id' => $a->grupo_id,
            'modo' => 'secuencia',
            'tramos' => [
                ['turno_id' => $a->id, 'profesional_id' => $this->ana->id, 'profesional_nombre' => 'Ana', 'fecha_hora' => '2099-06-10T10:00:00', 'duracion_total_minutos' => 30, 'estado' => 'confirmado'],
                ['turno_id' => $b->id, 'profesional_id' => $this->laura->id, 'profesional_nombre' => 'Laura', 'fecha_hora' => '2099-06-10T11:00:00', 'duracion_total_minutos' => 45, 'estado' => 'confirmado'],
            ],
        ];

        $lista = collect($this->admin()->getJson('/api/turnos')->assertOk()->json());
        $this->assertSame($esperado, $lista->firstWhere('id', $b->id)['grupo']);
        $this->assertSame($esperado, $this->admin()->getJson("/api/turnos/{$a->id}")->assertOk()->json('grupo'));
    }

    public function test_un_tramo_cancelado_sigue_figurando_en_el_grupo_con_su_estado(): void
    {
        [$a, $b] = $this->crearGrupo();
        $b->update(['estado' => 'cancelado']);

        $tramos = $this->admin()->getJson("/api/turnos/{$a->id}")->assertOk()->json('grupo.tramos');

        $this->assertSame(['confirmado', 'cancelado'], array_column($tramos, 'estado'));
    }
}

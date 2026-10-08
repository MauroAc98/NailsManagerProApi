<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\TurnoGrupo;
use App\Models\WhatsappTemplate;
use Tests\Feature\Contract\AdminContractTestCase;

/**
 * Un combo atendido por mas de una profesional le dice a la clienta que servicio
 * hace cada una. Si lo atiende una sola, el mensaje queda como siempre.
 * Prueba los parametros que viajan a la plantilla de Meta; nada sale a la red.
 */
class ConfirmacionServiciosPorProfesionalTest extends AdminContractTestCase
{
    private Profesional $laura;
    private Servicio $pedicura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->laura = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Laura Gomez', 'activo' => true]);
        $this->pedicura = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Pedicura', 'duracion_minutos' => 45, 'precio' => 1500, 'activo' => true]);
    }

    /** Ana hace Mani a las 10:00 y la profesional dada hace Pedicura a las 11:00, en un mismo grupo. */
    private function combo(Profesional $segunda): Turno
    {
        $g = TurnoGrupo::create(['modo' => 'secuencia']);
        $a = $this->crearTurno('confirmado', '2099-06-10 10:00:00');
        $b = $this->crearTurno('confirmado', '2099-06-10 11:00:00');
        $b->servicios()->sync([$this->pedicura->id]);
        $a->update(['grupo_id' => $g->id]);
        $b->update(['grupo_id' => $g->id, 'profesional_id' => $segunda->id]);

        return $a->fresh();
    }

    private function params(Turno $t, string $tipo = 'confirmacion'): array
    {
        return WhatsappTemplate::parametrosCloudApi($tipo, $this->cliente, $t, $this->user->fresh());
    }

    public function test_combo_con_dos_profesionales_dice_que_servicio_hace_cada_una(): void
    {
        $p = $this->params($this->combo($this->laura));

        $this->assertSame('Mani con Ana · Pedicura con Laura', $p[4]);
    }

    public function test_combo_con_dos_profesionales_avisa_en_nombre_del_equipo(): void
    {
        $p = $this->params($this->combo($this->laura));

        $this->assertSame('el equipo', $p[6]);
    }

    public function test_el_texto_legible_concuerda_en_singular(): void
    {
        $texto = WhatsappTemplate::mensajeLegible('confirmacion', $this->cliente, $this->combo($this->laura), $this->user->fresh());

        $this->assertStringContainsString('Mani con Ana · Pedicura con Laura', $texto);
        $this->assertStringContainsString('*el equipo no lo recibe y no puede contestarte.*', $texto);
    }

    public function test_combo_con_la_misma_profesional_queda_como_siempre(): void
    {
        $p = $this->params($this->combo($this->ana));

        $this->assertSame('Mani + Pedicura', $p[4]);
        $this->assertSame('Ana', $p[6]);
    }

    public function test_turno_suelto_queda_como_siempre(): void
    {
        $p = $this->params($this->crearTurno('confirmado', '2099-06-10 10:00:00'));

        $this->assertSame('Mani', $p[4]);
        $this->assertSame('Ana', $p[6]);
    }

    public function test_un_tramo_cancelado_no_cuenta(): void
    {
        $a = $this->combo($this->laura);
        Turno::where('grupo_id', $a->grupo_id)->where('profesional_id', $this->laura->id)->update(['estado' => 'cancelado']);

        $p = $this->params($a->fresh());

        $this->assertSame('Mani', $p[4]);
        $this->assertSame('Ana', $p[6]);
    }

    public function test_recordatorio_y_seña_usan_el_mismo_detalle(): void
    {
        $a = $this->combo($this->laura);

        $this->assertSame('Mani con Ana · Pedicura con Laura', $this->params($a, 'recordatorio')[4]);
        $sena = $this->params($a, 'reserva_sena');
        $this->assertSame('Mani con Ana · Pedicura con Laura', $sena[4]);
        $this->assertSame('el equipo', $sena[8]);
    }
}

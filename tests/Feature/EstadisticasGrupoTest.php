<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\TurnoGrupo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * combo-multi-profesional, PR 6a: las estadisticas atribuyen el ingreso de un
 * grupo a cada profesional via el precio (prorrateado) de SU turno, sin doble
 * conteo en el total. Los numeros de un turno sin grupo no cambian.
 */
class EstadisticasGrupoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profesional $ana;

    private Profesional $laura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_exempt' => true]);
        $this->ana = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'activo' => true]);
        $this->laura = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Laura', 'activo' => true]);
    }

    private function completado(Profesional $p, int $precio, string $hora, ?int $grupoId = null): Turno
    {
        $cliente = Cliente::firstOrCreate(['user_id' => $this->user->id, 'telefono' => '+543765252395'], ['nombre' => 'Marta']);
        $servicio = Servicio::create(['user_id' => $this->user->id, 'nombre' => "S{$hora}", 'duracion_minutos' => 30, 'precio' => 9999, 'activo' => true]);
        $t = Turno::create([
            'user_id' => $this->user->id, 'cliente_id' => $cliente->id, 'profesional_id' => $p->id, 'grupo_id' => $grupoId,
            'fecha_hora' => "2099-06-10 {$hora}:00", 'duracion_total_minutos' => 30, 'estado' => 'completado', 'origen' => 'app',
        ]);
        $t->servicios()->attach($servicio->id, ['precio' => $precio, 'precio_sugerido' => $precio]);

        return $t;
    }

    private function dashboard(array $extra = []): array
    {
        return $this->actingAs($this->user->fresh(), 'sanctum')
            ->getJson('/api/stats/dashboard?'.http_build_query(['desde' => '2099-06-01', 'hasta' => '2099-06-30'] + $extra))
            ->assertOk()->json();
    }

    public function test_cada_profesional_ve_solo_el_ingreso_prorrateado_de_su_tramo_y_el_total_no_duplica(): void
    {
        $g = TurnoGrupo::create(['modo' => 'secuencia']);
        $this->completado($this->ana, 10636, '10:00', $g->id);
        $this->completado($this->laura, 7364, '11:00', $g->id);

        $this->assertEquals(10636, $this->dashboard(['profesional_id' => $this->ana->id])['ingresos_agenda']);
        $this->assertEquals(7364, $this->dashboard(['profesional_id' => $this->laura->id])['ingresos_agenda']);
        $this->assertEquals(18000, $this->dashboard()['ingresos_agenda']);
    }

    public function test_un_turno_sin_grupo_suma_igual_que_siempre(): void
    {
        $this->completado($this->ana, 5000, '10:00');

        $d = $this->dashboard(['profesional_id' => $this->ana->id]);

        $this->assertEquals(5000, $d['ingresos_agenda']);
        $this->assertEquals(5000, $d['ganancias']);
        $this->assertSame(1, $d['turnos_por_estado']['completados']);
    }
}

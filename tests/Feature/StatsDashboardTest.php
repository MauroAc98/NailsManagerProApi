<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Gasto;
use App\Models\Ingreso;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StatsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurno(User $user, Profesional $profesional, Cliente $cliente, array $servicios, string $fechaHora, string $estado = 'completado'): Turno
    {
        $turno = Turno::create([
            'user_id' => $user->id,
            'profesional_id' => $profesional->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => $fechaHora,
            'duracion_total_minutos' => 30,
            'estado' => $estado,
            'origen' => 'app',
        ]);
        $turno->servicios()->attach(collect($servicios)->pluck('id'));

        return $turno;
    }

    public function test_cuenta_servicios_mas_pedidos_ordenados_por_cantidad(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);

        $manicura = Servicio::create(['user_id' => $user->id, 'nombre' => 'Manicura', 'duracion_minutos' => 30, 'activo' => true]);
        $pedicura = Servicio::create(['user_id' => $user->id, 'nombre' => 'Pedicura', 'duracion_minutos' => 30, 'activo' => true]);

        $this->crearTurno($user, $profesional, $cliente, [$manicura], '2026-07-05 10:00:00');
        $this->crearTurno($user, $profesional, $cliente, [$manicura], '2026-07-10 10:00:00');
        $this->crearTurno($user, $profesional, $cliente, [$pedicura], '2026-07-12 10:00:00');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $this->assertSame(3, $response->json('total_turnos'));

        $servicios = $response->json('servicios_mas_pedidos');

        $this->assertSame('Manicura', $servicios[0]['nombre']);
        $this->assertSame(2, $servicios[0]['cantidad']);
        $this->assertSame('Pedicura', $servicios[1]['nombre']);
        $this->assertSame(1, $servicios[1]['cantidad']);
    }

    public function test_no_cuenta_turnos_cancelados(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);
        $servicio = Servicio::create(['user_id' => $user->id, 'nombre' => 'Manicura', 'duracion_minutos' => 30, 'activo' => true]);

        $this->crearTurno($user, $profesional, $cliente, [$servicio], '2026-07-05 10:00:00', 'cancelado');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $this->assertSame([], $response->json('servicios_mas_pedidos'));
        $this->assertSame(['nuevas' => 0, 'recurrentes' => 0], $response->json('clientes'));
        $this->assertSame(['completados' => 0, 'confirmados' => 0, 'cancelados' => 1], $response->json('turnos_por_estado'));
    }

    public function test_desglosa_turnos_por_estado(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);
        $servicio = Servicio::create(['user_id' => $user->id, 'nombre' => 'Manicura', 'duracion_minutos' => 30, 'activo' => true]);

        $this->crearTurno($user, $profesional, $cliente, [$servicio], '2026-07-05 10:00:00', 'completado');
        $this->crearTurno($user, $profesional, $cliente, [$servicio], '2026-07-06 10:00:00', 'confirmado');
        $this->crearTurno($user, $profesional, $cliente, [$servicio], '2026-07-07 10:00:00', 'confirmado');
        $this->crearTurno($user, $profesional, $cliente, [$servicio], '2026-07-08 10:00:00', 'cancelado');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $this->assertSame(3, $response->json('total_turnos'));
        $this->assertSame(
            ['completados' => 1, 'confirmados' => 2, 'cancelados' => 1],
            $response->json('turnos_por_estado')
        );
    }

    public function test_desglosa_turnos_por_dia_semana(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);
        $servicio = Servicio::create(['user_id' => $user->id, 'nombre' => 'Manicura', 'duracion_minutos' => 30, 'activo' => true]);

        $this->crearTurno($user, $profesional, $cliente, [$servicio], '2026-07-06 10:00:00', 'completado');
        $this->crearTurno($user, $profesional, $cliente, [$servicio], '2026-07-06 11:00:00', 'completado');
        $this->crearTurno($user, $profesional, $cliente, [$servicio], '2026-07-06 12:00:00', 'cancelado');
        $this->crearTurno($user, $profesional, $cliente, [$servicio], '2026-07-07 10:00:00', 'confirmado');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $porDia = collect($response->json('turnos_por_estado_por_dia_semana'));
        // Siempre las 7 entradas — el frontend dibuja un eje fijo, no rellena huecos.
        $this->assertCount(7, $porDia);

        $lunesIso = Carbon::parse('2026-07-06')->isoWeekday();
        $martesIso = Carbon::parse('2026-07-07')->isoWeekday();

        $this->assertSame(
            ['dia_semana' => $lunesIso, 'completados' => 2, 'confirmados' => 0, 'cancelados' => 1],
            $porDia->firstWhere('dia_semana', $lunesIso)
        );
        $this->assertSame(
            ['dia_semana' => $martesIso, 'completados' => 0, 'confirmados' => 1, 'cancelados' => 0],
            $porDia->firstWhere('dia_semana', $martesIso)
        );

        $otro = $porDia->first(fn ($d) => ! in_array($d['dia_semana'], [$lunesIso, $martesIso]));
        $this->assertSame(0, $otro['completados'] + $otro['confirmados'] + $otro['cancelados']);
    }

    public function test_clasifica_cliente_nueva_vs_recurrente_segun_su_primer_turno_historico(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $servicio = Servicio::create(['user_id' => $user->id, 'nombre' => 'Manicura', 'duracion_minutos' => 30, 'activo' => true]);

        // Cliente A: su primer turno fue en junio (antes del período) -> recurrente en julio.
        $clienteA = Cliente::create(['user_id' => $user->id, 'nombre' => 'A', 'telefono' => '3765252391']);
        $this->crearTurno($user, $profesional, $clienteA, [$servicio], '2026-06-15 10:00:00');
        $this->crearTurno($user, $profesional, $clienteA, [$servicio], '2026-07-10 10:00:00');

        // Cliente B: su primer turno ES en julio (dentro del período) -> nueva.
        $clienteB = Cliente::create(['user_id' => $user->id, 'nombre' => 'B', 'telefono' => '3765252392']);
        $this->crearTurno($user, $profesional, $clienteB, [$servicio], '2026-07-20 10:00:00');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $this->assertSame(['nuevas' => 1, 'recurrentes' => 1], $response->json('clientes'));
    }

    public function test_filtra_por_profesional_puntual(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $jefa = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $empleada = Profesional::create(['user_id' => $user->id, 'nombre' => 'Empleada', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);
        $servicio = Servicio::create(['user_id' => $user->id, 'nombre' => 'Manicura', 'duracion_minutos' => 30, 'activo' => true]);

        $this->crearTurno($user, $jefa, $cliente, [$servicio], '2026-07-05 10:00:00');
        $this->crearTurno($user, $empleada, $cliente, [$servicio], '2026-07-06 10:00:00');
        $this->crearTurno($user, $empleada, $cliente, [$servicio], '2026-07-07 10:00:00');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31&profesional_id={$empleada->id}")
            ->assertOk();

        $servicios = $response->json('servicios_mas_pedidos');
        $this->assertCount(1, $servicios);
        $this->assertSame(2, $servicios[0]['cantidad']);
    }

    public function test_incluye_gastos_y_ganancia_neta_en_dashboard(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);
        $servicio = Servicio::create(['user_id' => $user->id, 'nombre' => 'Manicura', 'duracion_minutos' => 30, 'activo' => true]);

        $turno = $this->crearTurno($user, $profesional, $cliente, [$servicio], '2026-07-10 10:00:00');
        $turno->servicios()->updateExistingPivot($servicio->id, ['precio' => 1000]);

        Gasto::create([
            'user_id' => $user->id,
            'fecha' => '2026-07-15',
            'monto' => 300,
            'categoria' => 'insumos',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $this->assertSame(1000, (int) $response->json('ganancias'));
        $this->assertSame(300, (int) $response->json('gastos'));
        $this->assertSame(700, (int) $response->json('ganancia_neta'));
    }

    public function test_gastos_y_ganancia_neta_en_cero_sin_gastos(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);
        $servicio = Servicio::create(['user_id' => $user->id, 'nombre' => 'Manicura', 'duracion_minutos' => 30, 'activo' => true]);

        $turno = $this->crearTurno($user, $profesional, $cliente, [$servicio], '2026-07-10 10:00:00');
        $turno->servicios()->updateExistingPivot($servicio->id, ['precio' => 500]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $this->assertSame(0, (int) $response->json('gastos'));
        $this->assertSame(500, (int) $response->json('ganancia_neta'));
        $this->assertSame($response->json('ganancias'), $response->json('ganancia_neta'));
    }

    public function test_gastos_respeta_los_bordes_del_rango_de_fechas(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        Gasto::create(['user_id' => $user->id, 'fecha' => '2026-07-01', 'monto' => 100, 'categoria' => 'insumos']);
        Gasto::create(['user_id' => $user->id, 'fecha' => '2026-07-31', 'monto' => 200, 'categoria' => 'alquiler']);
        Gasto::create(['user_id' => $user->id, 'fecha' => '2026-06-30', 'monto' => 999, 'categoria' => 'marketing']);
        Gasto::create(['user_id' => $user->id, 'fecha' => '2026-08-01', 'monto' => 999, 'categoria' => 'marketing']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $this->assertSame(300, (int) $response->json('gastos'));
    }

    public function test_filtra_gastos_por_profesional_puntual(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $jefa = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $empleada = Profesional::create(['user_id' => $user->id, 'nombre' => 'Empleada', 'activo' => true]);

        Gasto::create(['user_id' => $user->id, 'profesional_id' => $jefa->id, 'fecha' => '2026-07-05', 'monto' => 100, 'categoria' => 'insumos']);
        Gasto::create(['user_id' => $user->id, 'profesional_id' => $empleada->id, 'fecha' => '2026-07-06', 'monto' => 250, 'categoria' => 'insumos']);
        Gasto::create(['user_id' => $user->id, 'fecha' => '2026-07-07', 'monto' => 50, 'categoria' => 'otros']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31&profesional_id={$empleada->id}")
            ->assertOk();

        $this->assertSame(250, (int) $response->json('gastos'));
    }

    // ── Ingresos (agenda + otros) ────────────────────────────────

    public function test_ingresos_agenda_espeja_ganancias_y_compat_se_mantiene(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);
        $servicio = Servicio::create(['user_id' => $user->id, 'nombre' => 'Manicura', 'duracion_minutos' => 30, 'activo' => true]);

        $turno = $this->crearTurno($user, $profesional, $cliente, [$servicio], '2026-07-10 10:00:00');
        $turno->servicios()->updateExistingPivot($servicio->id, ['precio' => 1000]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $this->assertSame(1000, (int) $response->json('ganancias'));
        $this->assertSame(1000, (int) $response->json('ingresos_agenda'));
        $this->assertSame($response->json('ganancias'), $response->json('ingresos_agenda'));
    }

    public function test_suma_ingresos_otros_del_rango(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        Ingreso::create(['user_id' => $user->id, 'fecha' => '2026-07-10', 'monto' => 400, 'categoria' => 'venta_productos']);
        Ingreso::create(['user_id' => $user->id, 'fecha' => '2026-07-20', 'monto' => 150, 'categoria' => 'otros']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $this->assertSame(550, (int) $response->json('ingresos_otros'));
    }

    public function test_ingresos_otros_respeta_los_bordes_del_rango_de_fechas(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        Ingreso::create(['user_id' => $user->id, 'fecha' => '2026-07-01', 'monto' => 100, 'categoria' => 'venta_productos']);
        Ingreso::create(['user_id' => $user->id, 'fecha' => '2026-07-31', 'monto' => 200, 'categoria' => 'alquiler_espacio']);
        Ingreso::create(['user_id' => $user->id, 'fecha' => '2026-06-30', 'monto' => 999, 'categoria' => 'otros']);
        Ingreso::create(['user_id' => $user->id, 'fecha' => '2026-08-01', 'monto' => 999, 'categoria' => 'otros']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $this->assertSame(300, (int) $response->json('ingresos_otros'));
    }

    public function test_ingresos_otros_solo_cuenta_los_del_usuario(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        Ingreso::create(['user_id' => $user->id, 'fecha' => '2026-07-10', 'monto' => 100, 'categoria' => 'otros']);
        Ingreso::create(['user_id' => $otroUsuario->id, 'fecha' => '2026-07-10', 'monto' => 999, 'categoria' => 'otros']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $this->assertSame(100, (int) $response->json('ingresos_otros'));
    }

    public function test_desglosa_ingresos_otros_por_categoria_con_las_tres_categorias(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        Ingreso::create(['user_id' => $user->id, 'fecha' => '2026-07-10', 'monto' => 400, 'categoria' => 'venta_productos']);
        Ingreso::create(['user_id' => $user->id, 'fecha' => '2026-07-11', 'monto' => 100, 'categoria' => 'venta_productos']);
        Ingreso::create(['user_id' => $user->id, 'fecha' => '2026-07-20', 'monto' => 250, 'categoria' => 'otros']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $porCategoria = collect($response->json('ingresos_otros_por_categoria'));

        // Siempre las 3 categorías del enum, incluso las que quedaron en 0.
        $this->assertCount(3, $porCategoria);
        $this->assertSame(
            ['venta_productos', 'alquiler_espacio', 'otros'],
            $porCategoria->pluck('categoria')->all()
        );
        $this->assertSame(500, (int) $porCategoria->firstWhere('categoria', 'venta_productos')['monto']);
        $this->assertSame(0, (int) $porCategoria->firstWhere('categoria', 'alquiler_espacio')['monto']);
        $this->assertSame(250, (int) $porCategoria->firstWhere('categoria', 'otros')['monto']);
    }

    public function test_ganancia_neta_recalculada_con_ingresos_agenda_mas_otros_menos_gastos(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);
        $servicio = Servicio::create(['user_id' => $user->id, 'nombre' => 'Manicura', 'duracion_minutos' => 30, 'activo' => true]);

        $turno = $this->crearTurno($user, $profesional, $cliente, [$servicio], '2026-07-10 10:00:00');
        $turno->servicios()->updateExistingPivot($servicio->id, ['precio' => 1000]);

        Ingreso::create(['user_id' => $user->id, 'fecha' => '2026-07-12', 'monto' => 500, 'categoria' => 'venta_productos']);
        Gasto::create(['user_id' => $user->id, 'fecha' => '2026-07-15', 'monto' => 300, 'categoria' => 'insumos']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        // 1000 (agenda) + 500 (otros) - 300 (gastos) = 1200
        $this->assertSame(1200, (int) $response->json('ganancia_neta'));
    }

    public function test_ingresos_otros_van_a_cero_cuando_se_filtra_por_profesional(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $empleada = Profesional::create(['user_id' => $user->id, 'nombre' => 'Empleada', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);
        $servicio = Servicio::create(['user_id' => $user->id, 'nombre' => 'Manicura', 'duracion_minutos' => 30, 'activo' => true]);

        $turno = $this->crearTurno($user, $empleada, $cliente, [$servicio], '2026-07-10 10:00:00');
        $turno->servicios()->updateExistingPivot($servicio->id, ['precio' => 800]);

        Ingreso::create(['user_id' => $user->id, 'fecha' => '2026-07-12', 'monto' => 500, 'categoria' => 'venta_productos']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/stats/dashboard?desde=2026-07-01&hasta=2026-07-31&profesional_id={$empleada->id}")
            ->assertOk();

        // Los ingresos "otros" no tienen profesional -> 0 al filtrar.
        $this->assertSame(0, (int) $response->json('ingresos_otros'));
        $porCategoria = collect($response->json('ingresos_otros_por_categoria'));
        $this->assertCount(3, $porCategoria);
        $this->assertSame(0, (int) $porCategoria->sum(fn ($c) => (int) $c['monto']));
        // La agenda sí se sigue filtrando por profesional.
        $this->assertSame(800, (int) $response->json('ingresos_agenda'));
        $this->assertSame(800, (int) $response->json('ganancia_neta'));
    }
}

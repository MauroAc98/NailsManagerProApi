<?php

namespace Tests\Feature;

use App\Models\Cliente;
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
    }

    public function test_clasifica_clienta_nueva_vs_recurrente_segun_su_primer_turno_historico(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $servicio = Servicio::create(['user_id' => $user->id, 'nombre' => 'Manicura', 'duracion_minutos' => 30, 'activo' => true]);

        // Clienta A: su primer turno fue en junio (antes del período) -> recurrente en julio.
        $clienteA = Cliente::create(['user_id' => $user->id, 'nombre' => 'A', 'telefono' => '3765252391']);
        $this->crearTurno($user, $profesional, $clienteA, [$servicio], '2026-06-15 10:00:00');
        $this->crearTurno($user, $profesional, $clienteA, [$servicio], '2026-07-10 10:00:00');

        // Clienta B: su primer turno ES en julio (dentro del período) -> nueva.
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
}

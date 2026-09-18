<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnoIndexProfesionalFiltroTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurno(User $user, Profesional $profesional, Cliente $cliente, $fechaHora): Turno
    {
        return Turno::create([
            'user_id' => $user->id,
            'profesional_id' => $profesional->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => $fechaHora,
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
    }

    // ── GET /api/turnos?profesional_id=... ─────────────────────────

    public function test_filtra_por_profesional_id_devolviendo_pasado_y_futuro(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $jefa = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $empleada = Profesional::create(['user_id' => $user->id, 'nombre' => 'Empleada', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Ana', 'telefono' => '3765252395']);

        $turnoPasadoJefa = $this->crearTurno($user, $jefa, $cliente, now()->subDays(10)->setTime(10, 0));
        $turnoFuturoJefa = $this->crearTurno($user, $jefa, $cliente, now()->addDays(10)->setTime(10, 0));
        $turnoEmpleada = $this->crearTurno($user, $empleada, $cliente, now()->addDay()->setTime(10, 0));

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/turnos?profesional_id={$jefa->id}")
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($turnoPasadoJefa->id, $ids);
        $this->assertContains($turnoFuturoJefa->id, $ids);
        $this->assertNotContains($turnoEmpleada->id, $ids);
    }

    // NOTA: no se prueba la combinación profesional_id + buscar acá porque
    // ese filtro usa ILIKE (Postgres-only, ver TurnoController::index) y la
    // suite corre contra sqlite (phpunit.xml) — ya era intestable así antes
    // de este cambio (no hay ningún test feature previo que ejercite
    // `buscar`). Se prueba la combinabilidad con servicio_id en cambio,
    // que usa whereHas/where estándar y sí corre en sqlite, para dejar
    // demostrado que profesional_id se AND-ea con el resto de los filtros
    // en vez de ser exclusivo.
    public function test_profesional_id_es_combinable_con_servicio_id(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $jefa = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Ana', 'telefono' => '3765252395']);

        $manicura = Servicio::create([
            'user_id' => $user->id,
            'nombre' => 'Manicura',
            'duracion_minutos' => 30,
            'precio' => 1000,
            'activo' => true,
        ]);
        $pedicura = Servicio::create([
            'user_id' => $user->id,
            'nombre' => 'Pedicura',
            'duracion_minutos' => 30,
            'precio' => 1200,
            'activo' => true,
        ]);

        $turnoManicura = $this->crearTurno($user, $jefa, $cliente, now()->addDay()->setTime(10, 0));
        $turnoManicura->servicios()->attach($manicura->id);

        $turnoPedicura = $this->crearTurno($user, $jefa, $cliente, now()->addDay()->setTime(12, 0));
        $turnoPedicura->servicios()->attach($pedicura->id);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/turnos?profesional_id={$jefa->id}&servicio_id={$manicura->id}")
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($turnoManicura->id, $ids);
        $this->assertNotContains($turnoPedicura->id, $ids);
    }

    public function test_profesional_id_no_filtra_turnos_de_otra_cuenta(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUser = User::factory()->create(['is_exempt' => true]);

        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $profesionalOtraCuenta = Profesional::create(['user_id' => $otroUser->id, 'nombre' => 'Jefa Otra Cuenta', 'activo' => true]);

        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Ana', 'telefono' => '3765252395']);
        $clienteOtraCuenta = Cliente::create(['user_id' => $otroUser->id, 'nombre' => 'Ana', 'telefono' => '3765252397']);

        $turnoPropio = $this->crearTurno($user, $profesional, $cliente, now()->addDay()->setTime(10, 0));
        $this->crearTurno($otroUser, $profesionalOtraCuenta, $clienteOtraCuenta, now()->addDay()->setTime(10, 0));

        // Pide explícitamente el id de la profesional de la OTRA cuenta —
        // Turno::delUsuario($user) debe ganarle: el resultado tiene que venir
        // vacío, nunca los turnos ajenos.
        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/turnos?profesional_id={$profesionalOtraCuenta->id}")
            ->assertOk();

        $this->assertSame([], $response->json());

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/turnos?profesional_id={$profesional->id}")
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertSame([$turnoPropio->id], $ids);
    }
}

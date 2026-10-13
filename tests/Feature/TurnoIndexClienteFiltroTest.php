<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnoIndexClienteFiltroTest extends TestCase
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

    // ── GET /api/turnos?cliente_id=... ─────────────────────────────

    public function test_filtra_por_cliente_id_devolviendo_pasado_y_futuro(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $jefa = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $ana = Cliente::create(['user_id' => $user->id, 'nombre' => 'Ana', 'telefono' => '3765252395']);
        $otraAna = Cliente::create(['user_id' => $user->id, 'nombre' => 'Ana', 'telefono' => '3765252396']);

        $pasado = $this->crearTurno($user, $jefa, $ana, now()->subDays(10)->setTime(10, 0));
        $futuro = $this->crearTurno($user, $jefa, $ana, now()->addDays(10)->setTime(10, 0));
        // Homónima: mismo nombre, otra persona — el filtro por id NO debe mezclarla.
        $deLaHomonima = $this->crearTurno($user, $jefa, $otraAna, now()->addDay()->setTime(10, 0));

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/turnos?cliente_id={$ana->id}")
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($pasado->id, $ids);
        $this->assertContains($futuro->id, $ids);
        $this->assertNotContains($deLaHomonima->id, $ids);
    }

    public function test_cliente_id_es_combinable_con_servicio_id(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $jefa = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $ana = Cliente::create(['user_id' => $user->id, 'nombre' => 'Ana', 'telefono' => '3765252395']);

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

        $turnoManicura = $this->crearTurno($user, $jefa, $ana, now()->addDay()->setTime(10, 0));
        $turnoManicura->servicios()->attach($manicura->id);
        $turnoPedicura = $this->crearTurno($user, $jefa, $ana, now()->addDay()->setTime(12, 0));
        $turnoPedicura->servicios()->attach($pedicura->id);

        $ids = collect(
            $this->actingAs($user, 'sanctum')
                ->getJson("/api/turnos?cliente_id={$ana->id}&servicio_id={$manicura->id}")
                ->assertOk()
                ->json()
        )->pluck('id')->all();

        $this->assertContains($turnoManicura->id, $ids);
        $this->assertNotContains($turnoPedicura->id, $ids);
    }

    public function test_cliente_id_no_filtra_turnos_de_otra_cuenta(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUser = User::factory()->create(['is_exempt' => true]);

        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $profesionalAjena = Profesional::create(['user_id' => $otroUser->id, 'nombre' => 'Jefa Ajena', 'activo' => true]);

        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Ana', 'telefono' => '3765252395']);
        $clienteAjena = Cliente::create(['user_id' => $otroUser->id, 'nombre' => 'Ana', 'telefono' => '3765252397']);

        $propio = $this->crearTurno($user, $profesional, $cliente, now()->addDay()->setTime(10, 0));
        $this->crearTurno($otroUser, $profesionalAjena, $clienteAjena, now()->addDay()->setTime(10, 0));

        // Pide el id de la clienta de OTRA cuenta — Turno::delUsuario($user)
        // debe ganarle: vacío, nunca turnos ajenos.
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/turnos?cliente_id={$clienteAjena->id}")
            ->assertOk()
            ->assertExactJson([]);

        $ids = collect(
            $this->actingAs($user, 'sanctum')
                ->getJson("/api/turnos?cliente_id={$cliente->id}")
                ->assertOk()
                ->json()
        )->pluck('id')->all();

        $this->assertSame([$propio->id], $ids);
    }
}

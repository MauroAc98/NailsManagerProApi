<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StatsOcupacionTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurno(User $user, Profesional $profesional, Cliente $cliente, string $fechaHora, string $estado = 'completado'): Turno
    {
        return Turno::create([
            'user_id' => $user->id,
            'profesional_id' => $profesional->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => $fechaHora,
            'duracion_total_minutos' => 30,
            'estado' => $estado,
            'origen' => 'app',
        ]);
    }

    public function test_agrupa_por_dia_semana_y_hora(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);

        $this->crearTurno($user, $profesional, $cliente, '2026-07-06 10:15:00');
        $this->crearTurno($user, $profesional, $cliente, '2026-07-06 10:45:00'); // mismo bucket que el anterior
        $this->crearTurno($user, $profesional, $cliente, '2026-07-06 14:00:00');
        $this->crearTurno($user, $profesional, $cliente, '2026-07-07 10:00:00');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/ocupacion?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $lunesIso = Carbon::parse('2026-07-06')->isoWeekday();
        $martesIso = Carbon::parse('2026-07-07')->isoWeekday();

        $buckets = collect($response->json());
        $this->assertCount(3, $buckets);

        $this->assertSame(2, $buckets->first(fn ($b) => $b['dia_semana'] === $lunesIso && $b['hora'] === 10)['cantidad']);
        $this->assertSame(1, $buckets->first(fn ($b) => $b['dia_semana'] === $lunesIso && $b['hora'] === 14)['cantidad']);
        $this->assertSame(1, $buckets->first(fn ($b) => $b['dia_semana'] === $martesIso && $b['hora'] === 10)['cantidad']);
    }

    public function test_no_cuenta_cancelados(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);

        $this->crearTurno($user, $profesional, $cliente, '2026-07-06 10:00:00', 'cancelado');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/ocupacion?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $this->assertSame([], $response->json());
    }

    public function test_filtra_por_profesional_puntual(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $jefa = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $empleada = Profesional::create(['user_id' => $user->id, 'nombre' => 'Empleada', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);

        $this->crearTurno($user, $jefa, $cliente, '2026-07-06 10:00:00');
        $this->crearTurno($user, $empleada, $cliente, '2026-07-06 11:00:00');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/stats/ocupacion?desde=2026-07-01&hasta=2026-07-31&profesional_id={$empleada->id}")
            ->assertOk();

        $buckets = collect($response->json());
        $this->assertCount(1, $buckets);
        $this->assertSame(11, $buckets->first()['hora']);
    }

    public function test_respeta_rango_de_fechas(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);

        $this->crearTurno($user, $profesional, $cliente, '2026-06-30 10:00:00');
        $this->crearTurno($user, $profesional, $cliente, '2026-07-15 10:00:00');
        $this->crearTurno($user, $profesional, $cliente, '2026-08-01 10:00:00');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/stats/ocupacion?desde=2026-07-01&hasta=2026-07-31')
            ->assertOk();

        $this->assertCount(1, $response->json());
    }
}

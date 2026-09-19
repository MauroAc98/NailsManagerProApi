<?php

namespace Tests\Feature;

use App\Models\BloqueoAgenda;
use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BloqueoAgendaControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── GET /api/bloqueos ─────────────────────────────────────────

    public function test_index_devuelve_solo_los_bloqueos_de_la_cuenta_ordenados_por_fecha(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        BloqueoAgenda::create(['user_id' => $otroUsuario->id, 'fecha' => '2099-01-01']);
        $segundo = BloqueoAgenda::create(['user_id' => $user->id, 'fecha' => '2099-06-15']);
        $primero = BloqueoAgenda::create(['user_id' => $user->id, 'fecha' => '2099-03-10']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/bloqueos')->assertOk();

        $response->assertJsonCount(2);
        $this->assertSame([$primero->id, $segundo->id], collect($response->json())->pluck('id')->all());
    }

    // ── POST /api/bloqueos ────────────────────────────────────────

    private function fechaFutura(int $dias = 30): string
    {
        return now()->addDays($dias)->toDateString();
    }

    public function test_store_crea_un_bloqueo_de_dia_completo_salon_wide(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bloqueos', ['fecha' => $this->fechaFutura(), 'motivo' => 'Feriado'])
            ->assertCreated();

        $response->assertJsonPath('profesional_id', null);
        $response->assertJsonPath('hora_desde', null);
        $response->assertJsonPath('hora_hasta', null);
        $this->assertDatabaseCount('bloqueos_agenda', 1);
    }

    public function test_store_crea_un_bloqueo_parcial_de_una_profesional(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $ana = Profesional::create(['user_id' => $user->id, 'nombre' => 'Ana', 'activo' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bloqueos', [
                'profesional_id' => $ana->id,
                'fecha' => $this->fechaFutura(),
                'hora_desde' => '13:00',
                'hora_hasta' => '18:00',
            ])
            ->assertCreated();

        $response->assertJsonPath('profesional_id', $ana->id);
    }

    public function test_store_rechaza_un_solo_horario_sin_el_otro(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/bloqueos', ['fecha' => $this->fechaFutura(), 'hora_desde' => '13:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hora_hasta']);

        $this->assertDatabaseCount('bloqueos_agenda', 0);
    }

    public function test_store_rechaza_hora_hasta_menor_o_igual_a_hora_desde(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/bloqueos', [
                'fecha' => $this->fechaFutura(),
                'hora_desde' => '18:00',
                'hora_hasta' => '13:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hora_hasta']);
    }

    public function test_store_rechaza_una_fecha_en_el_pasado(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/bloqueos', ['fecha' => now()->subDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fecha']);
    }

    public function test_store_rechaza_profesional_id_de_otra_cuenta(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);
        $profesionalAjena = Profesional::create(['user_id' => $otroUsuario->id, 'nombre' => 'Bea', 'activo' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/bloqueos', ['profesional_id' => $profesionalAjena->id, 'fecha' => $this->fechaFutura()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profesional_id']);

        $this->assertDatabaseCount('bloqueos_agenda', 0);
    }

    public function test_store_rechaza_un_duplicado_exacto(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $ana = Profesional::create(['user_id' => $user->id, 'nombre' => 'Ana', 'activo' => true]);
        $fecha = $this->fechaFutura();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/bloqueos', ['profesional_id' => $ana->id, 'fecha' => $fecha, 'hora_desde' => '13:00', 'hora_hasta' => '18:00'])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/bloqueos', ['profesional_id' => $ana->id, 'fecha' => $fecha, 'hora_desde' => '13:00', 'hora_hasta' => '18:00'])
            ->assertStatus(409);

        $this->assertDatabaseCount('bloqueos_agenda', 1);
    }

    // ── DELETE /api/bloqueos/{id} ────────────────────────────────

    public function test_destroy_borra_un_bloqueo_propio(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $bloqueo = BloqueoAgenda::create(['user_id' => $user->id, 'fecha' => $this->fechaFutura()]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/bloqueos/{$bloqueo->id}")
            ->assertOk();

        $this->assertDatabaseMissing('bloqueos_agenda', ['id' => $bloqueo->id]);
    }

    public function test_destroy_de_un_bloqueo_ajeno_devuelve_404(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);
        $ajeno = BloqueoAgenda::create(['user_id' => $otroUsuario->id, 'fecha' => $this->fechaFutura()]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/bloqueos/{$ajeno->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('bloqueos_agenda', ['id' => $ajeno->id]);
    }
}

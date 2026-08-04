<?php

namespace Tests\Feature;

use App\Models\Gasto;
use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GastoCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_un_gasto_con_todos_los_campos(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/gastos', [
                'fecha' => '2026-08-01',
                'monto' => 1500.50,
                'categoria' => 'insumos',
                'descripcion' => 'Esmaltes y limas',
                'profesional_id' => $profesional->id,
            ]);

        $response->assertCreated();
        $response->assertJsonFragment([
            'categoria' => 'insumos',
            'descripcion' => 'Esmaltes y limas',
            'profesional_id' => $profesional->id,
        ]);

        $this->assertDatabaseHas('gastos', [
            'user_id' => $user->id,
            'categoria' => 'insumos',
            'profesional_id' => $profesional->id,
        ]);
    }

    public function test_crea_un_gasto_solo_con_campos_requeridos(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/gastos', [
                'fecha' => '2026-08-01',
                'monto' => 500,
                'categoria' => 'alquiler',
            ]);

        $response->assertCreated();
        $response->assertJsonFragment([
            'categoria' => 'alquiler',
            'descripcion' => null,
            'profesional_id' => null,
        ]);
    }

    public function test_rechaza_categoria_invalida(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/gastos', [
                'fecha' => '2026-08-01',
                'monto' => 500,
                'categoria' => 'categoria_inventada',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['categoria']);

        $this->assertDatabaseCount('gastos', 0);
    }

    public function test_lista_solo_los_gastos_propios(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        $user->gastos()->create(['fecha' => '2026-08-01', 'monto' => 100, 'categoria' => 'insumos']);
        $user->gastos()->create(['fecha' => '2026-08-02', 'monto' => 200, 'categoria' => 'marketing']);
        $user->gastos()->create(['fecha' => '2026-08-03', 'monto' => 300, 'categoria' => 'otros']);

        $otroUsuario->gastos()->create(['fecha' => '2026-08-01', 'monto' => 999, 'categoria' => 'alquiler']);
        $otroUsuario->gastos()->create(['fecha' => '2026-08-02', 'monto' => 999, 'categoria' => 'alquiler']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/gastos');

        $response->assertOk();
        $response->assertJsonCount(3);
    }

    public function test_muestra_un_gasto_ajeno_devuelve_404(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        $gastoAjeno = $otroUsuario->gastos()->create(['fecha' => '2026-08-01', 'monto' => 999, 'categoria' => 'alquiler']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/gastos/{$gastoAjeno->id}")
            ->assertStatus(404);
    }

    public function test_actualiza_monto_y_categoria(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $gasto = $user->gastos()->create([
            'fecha' => '2026-08-01',
            'monto' => 100,
            'categoria' => 'insumos',
            'descripcion' => 'Original',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/gastos/{$gasto->id}", [
                'monto' => 250.75,
                'categoria' => 'marketing',
            ]);

        $response->assertOk();
        $response->assertJsonFragment([
            'categoria' => 'marketing',
            'descripcion' => 'Original',
        ]);

        $this->assertDatabaseHas('gastos', [
            'id' => $gasto->id,
            'monto' => 250.75,
            'categoria' => 'marketing',
        ]);
    }

    public function test_elimina_un_gasto_sin_condiciones(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $gasto = $user->gastos()->create(['fecha' => '2026-08-01', 'monto' => 100, 'categoria' => 'insumos']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/gastos/{$gasto->id}")
            ->assertOk();

        $this->assertDatabaseMissing('gastos', ['id' => $gasto->id]);
    }

    public function test_eliminar_un_gasto_ajeno_devuelve_404(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        $gastoAjeno = $otroUsuario->gastos()->create(['fecha' => '2026-08-01', 'monto' => 999, 'categoria' => 'alquiler']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/gastos/{$gastoAjeno->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('gastos', ['id' => $gastoAjeno->id]);
    }

    public function test_gasto_sobrevive_a_la_desactivacion_del_profesional(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $gasto = $user->gastos()->create([
            'fecha' => '2026-08-01',
            'monto' => 100,
            'categoria' => 'insumos',
            'profesional_id' => $profesional->id,
        ]);

        $profesional->update(['activo' => false]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/gastos/{$gasto->id}");

        $response->assertOk();
        $response->assertJsonFragment(['profesional_id' => $profesional->id]);
    }

    public function test_gasto_sobrevive_al_borrado_del_profesional(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $gasto = $user->gastos()->create([
            'fecha' => '2026-08-01',
            'monto' => 100,
            'categoria' => 'insumos',
            'profesional_id' => $profesional->id,
        ]);

        $profesional->delete();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/gastos/{$gasto->id}");

        $response->assertOk();
        $response->assertJsonFragment(['profesional_id' => null]);
    }

    public function test_rechaza_profesional_id_ajeno(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);
        $profesionalAjeno = Profesional::create(['user_id' => $otroUsuario->id, 'nombre' => 'Otra', 'activo' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/gastos', [
                'fecha' => '2026-08-01',
                'monto' => 100,
                'categoria' => 'insumos',
                'profesional_id' => $profesionalAjeno->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['profesional_id']);
    }
}

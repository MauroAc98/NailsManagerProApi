<?php

namespace Tests\Feature;

use App\Models\CategoriaServicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoriaServicioCrudTest extends TestCase
{
    use RefreshDatabase;

    // ── Aislamiento multi-tenant ─────────────────────────────────

    public function test_muestra_una_categoria_ajena_devuelve_404(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        $categoriaAjena = CategoriaServicio::create(['user_id' => $otroUsuario->id, 'nombre' => 'Manicura']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/categorias-servicio/{$categoriaAjena->id}")
            ->assertStatus(404);
    }

    public function test_actualizar_una_categoria_ajena_devuelve_404(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        $categoriaAjena = CategoriaServicio::create(['user_id' => $otroUsuario->id, 'nombre' => 'Manicura']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/categorias-servicio/{$categoriaAjena->id}", ['nombre' => 'Pedicura'])
            ->assertStatus(404);

        $this->assertDatabaseHas('categorias_servicio', ['id' => $categoriaAjena->id, 'nombre' => 'Manicura']);
    }

    public function test_eliminar_una_categoria_ajena_devuelve_404(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        $categoriaAjena = CategoriaServicio::create(['user_id' => $otroUsuario->id, 'nombre' => 'Manicura']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/categorias-servicio/{$categoriaAjena->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('categorias_servicio', ['id' => $categoriaAjena->id]);
    }

    // ── categoria_id cruzado en Servicio ─────────────────────────

    public function test_rechaza_categoria_id_ajena_al_crear_un_servicio(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        $categoriaAjena = CategoriaServicio::create(['user_id' => $otroUsuario->id, 'nombre' => 'Manicura']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/servicios', [
                'nombre' => 'Nail Art',
                'duracion_minutos' => 45,
                'precio' => 2000,
                'categoria_id' => $categoriaAjena->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['categoria_id']);

        $this->assertDatabaseCount('servicios', 0);
    }

    public function test_rechaza_categoria_id_ajena_al_actualizar_un_servicio(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        $categoriaAjena = CategoriaServicio::create(['user_id' => $otroUsuario->id, 'nombre' => 'Manicura']);

        $servicio = $user->servicios()->create([
            'nombre' => 'Nail Art',
            'duracion_minutos' => 45,
            'precio' => 2000,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/servicios/{$servicio->id}", [
                'categoria_id' => $categoriaAjena->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['categoria_id']);

        $this->assertDatabaseHas('servicios', ['id' => $servicio->id, 'categoria_id' => null]);
    }

    // ── Unicidad case-insensitive con acentos ────────────────────

    public function test_rechaza_nombre_duplicado_con_mayusculas_y_tildes(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/categorias-servicio', ['nombre' => 'Depilación'])
            ->assertCreated();

        $mayusculas = $this->actingAs($user, 'sanctum')
            ->postJson('/api/categorias-servicio', ['nombre' => 'DEPILACIÓN']);

        $mayusculas->assertStatus(422);
        $mayusculas->assertJsonValidationErrors(['nombre']);

        $minusculas = $this->actingAs($user, 'sanctum')
            ->postJson('/api/categorias-servicio', ['nombre' => 'depilación']);

        $minusculas->assertStatus(422);
        $minusculas->assertJsonValidationErrors(['nombre']);

        $this->assertDatabaseCount('categorias_servicio', 1);
    }

    public function test_permite_el_mismo_nombre_con_tildes_para_otro_usuario(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        CategoriaServicio::create(['user_id' => $user->id, 'nombre' => 'Depilación']);

        $response = $this->actingAs($otroUsuario, 'sanctum')
            ->postJson('/api/categorias-servicio', ['nombre' => 'Depilación']);

        $response->assertCreated();

        $this->assertDatabaseCount('categorias_servicio', 2);
    }

    public function test_actualizar_una_categoria_manteniendo_su_propio_nombre_no_se_rechaza_como_duplicado(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $categoria = CategoriaServicio::create(['user_id' => $user->id, 'nombre' => 'Depilación']);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/categorias-servicio/{$categoria->id}", ['nombre' => 'DEPILACIÓN']);

        $response->assertOk();
        $this->assertSame('DEPILACIÓN', $categoria->fresh()->nombre);
    }

    // ── Borrado bloqueado con servicios asociados ────────────────

    public function test_rechaza_eliminar_una_categoria_con_servicios_asociados(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $categoria = CategoriaServicio::create(['user_id' => $user->id, 'nombre' => 'Manicura']);

        $user->servicios()->create([
            'nombre' => 'Nail Art',
            'duracion_minutos' => 45,
            'precio' => 2000,
            'categoria_id' => $categoria->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/categorias-servicio/{$categoria->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('categorias_servicio', ['id' => $categoria->id]);
    }

    public function test_elimina_una_categoria_sin_servicios_asociados(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $categoria = CategoriaServicio::create(['user_id' => $user->id, 'nombre' => 'Manicura']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/categorias-servicio/{$categoria->id}")
            ->assertOk();

        $this->assertDatabaseMissing('categorias_servicio', ['id' => $categoria->id]);
    }
}

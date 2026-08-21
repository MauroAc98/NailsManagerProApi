<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfesionalApellidoTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_persiste_apellido_y_arma_nombre_completo(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/profesionales', ['nombre' => 'Camila', 'apellido' => 'Ríos'])
            ->assertCreated();

        $response->assertJsonPath('apellido', 'Ríos');
        $response->assertJsonPath('nombre_completo', 'Camila Ríos');
    }

    public function test_store_sin_apellido_lo_deja_null_y_nombre_completo_es_solo_el_nombre(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/profesionales', ['nombre' => 'Camila'])
            ->assertCreated();

        $response->assertJsonPath('apellido', null);
        $response->assertJsonPath('nombre_completo', 'Camila');
    }

    public function test_update_patchea_apellido_sin_tocar_nombre(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Camila', 'activo' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$profesional->id}", ['apellido' => 'Ríos'])
            ->assertOk()
            ->assertJsonPath('apellido', 'Ríos')
            ->assertJsonPath('nombre', 'Camila');

        $this->assertSame('Ríos', $profesional->fresh()->apellido);
    }
}

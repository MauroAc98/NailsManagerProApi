<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdatePerfilUbicacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guardar_un_par_de_coordenadas_valido_se_permite(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'latitud' => null, 'longitud' => null]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'latitud' => -34.6,
                'longitud' => -58.4,
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(-34.6, $fresh->latitud);
        $this->assertSame(-58.4, $fresh->longitud);
    }

    public function test_latitud_fuera_de_rango_es_rechazada(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'latitud' => null, 'longitud' => null]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'latitud' => 91,
                'longitud' => -58.4,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('latitud');

        $this->assertNull($user->fresh()->latitud);
    }

    public function test_longitud_fuera_de_rango_es_rechazada(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'latitud' => null, 'longitud' => null]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'latitud' => -34.6,
                'longitud' => 181,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('longitud');

        $this->assertNull($user->fresh()->longitud);
    }

    public function test_enviar_solo_una_coordenada_es_rechazado(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'latitud' => null, 'longitud' => null]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'latitud' => -34.6,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('latitud');

        $this->assertNull($user->fresh()->latitud);
    }

    public function test_enviar_ambas_coordenadas_null_borra_la_ubicacion_guardada(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'latitud' => -34.6, 'longitud' => -58.4]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'latitud' => null,
                'longitud' => null,
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertNull($fresh->latitud);
        $this->assertNull($fresh->longitud);
    }

    public function test_editar_un_campo_no_relacionado_no_toca_la_ubicacion_guardada(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'latitud' => -34.6, 'longitud' => -58.4]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', ['locale' => 'pt-BR'])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('pt-BR', $fresh->locale);
        $this->assertSame(-34.6, $fresh->latitud);
        $this->assertSame(-58.4, $fresh->longitud);
    }
}

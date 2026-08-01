<?php

namespace Tests\Feature;

use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicioEsPromoTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_un_servicio_marcado_como_promo(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/servicios', [
                'nombre' => 'Nail Art',
                'duracion_minutos' => 45,
                'precio' => 2000,
                'es_promo' => true,
            ])
            ->assertCreated();

        $response->assertJsonPath('es_promo', true);
        $this->assertTrue(Servicio::findOrFail($response->json('id'))->es_promo);
    }

    public function test_un_servicio_nuevo_sin_es_promo_queda_en_false_por_default(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/servicios', [
                'nombre' => 'Manicura clásica',
                'duracion_minutos' => 30,
                'precio' => 1000,
            ])
            ->assertCreated();

        $response->assertJsonPath('es_promo', false);
    }

    public function test_actualiza_es_promo_de_un_servicio_existente(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $servicio = $user->servicios()->create([
            'nombre' => 'Nail Art',
            'duracion_minutos' => 45,
            'precio' => 2000,
            'es_promo' => false,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/servicios/{$servicio->id}", [
                'es_promo' => true,
            ])
            ->assertOk();

        $response->assertJsonPath('es_promo', true);
        $this->assertTrue($servicio->fresh()->es_promo);
    }
}

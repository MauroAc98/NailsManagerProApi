<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClienteCreacionActivoTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_cliente_recien_creado_devuelve_activo_true_en_la_respuesta(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/clientes', [
                'nombre' => 'Sofía',
                'apellido' => 'Gómez',
                'telefono' => '+543765252395',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('activo', true);
    }
}

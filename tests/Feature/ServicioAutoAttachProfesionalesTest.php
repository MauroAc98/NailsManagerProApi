<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicioAutoAttachProfesionalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_servicio_nuevo_queda_asignado_a_todas_las_profesionales_activas(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $jefa = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $empleada = Profesional::create(['user_id' => $user->id, 'nombre' => 'Empleada', 'activo' => true]);
        $inactiva = Profesional::create(['user_id' => $user->id, 'nombre' => 'Inactiva', 'activo' => false]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/servicios', [
                'nombre' => 'Nail Art',
                'duracion_minutos' => 45,
                'precio' => 2000,
            ])
            ->assertCreated();

        $servicioId = $response->json('id');

        $this->assertTrue($jefa->servicios()->where('servicios.id', $servicioId)->exists());
        $this->assertTrue($empleada->servicios()->where('servicios.id', $servicioId)->exists());
        $this->assertFalse($inactiva->servicios()->where('servicios.id', $servicioId)->exists());
    }
}

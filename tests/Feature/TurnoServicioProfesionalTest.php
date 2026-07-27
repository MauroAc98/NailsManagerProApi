<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnoServicioProfesionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_rechaza_agendar_un_servicio_no_asignado_a_la_profesional_elegida(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $jefa = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $empleada = Profesional::create(['user_id' => $user->id, 'nombre' => 'Empleada', 'activo' => true]);

        $servicioSoloDeJefa = Servicio::create([
            'user_id' => $user->id,
            'nombre' => 'Nail Art',
            'duracion_minutos' => 30,
            'precio' => 1000,
            'activo' => true,
        ]);
        $jefa->servicios()->attach($servicioSoloDeJefa->id);

        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);

        $fecha = now()->addDay()->format('Y-m-d');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/turnos', [
                'cliente_id' => $cliente->id,
                'servicio_ids' => [$servicioSoloDeJefa->id],
                'fecha_hora' => "{$fecha} 12:00:00",
                'profesional_id' => $empleada->id,
            ])
            ->assertStatus(422)
            ->assertJson(['message' => 'Alguno de los servicios seleccionados no está asignado a esta profesional.']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicioEliminarTest extends TestCase
{
    use RefreshDatabase;

    public function test_elimina_un_servicio_sin_turnos_asociados(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $servicio = $user->servicios()->create([
            'nombre' => 'Nail Art',
            'duracion_minutos' => 45,
            'precio' => 2000,
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/servicios/{$servicio->id}")
            ->assertOk();

        $this->assertDatabaseMissing('servicios', ['id' => $servicio->id]);
    }

    public function test_rechaza_eliminar_un_servicio_con_turnos_asociados(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);

        $servicio = $user->servicios()->create([
            'nombre' => 'Nail Art',
            'duracion_minutos' => 45,
            'precio' => 2000,
        ]);

        $turno = Turno::create([
            'user_id' => $user->id,
            'profesional_id' => $profesional->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addDay(),
            'duracion_total_minutos' => 45,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
        $turno->servicios()->attach($servicio->id);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/servicios/{$servicio->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('servicios', [
            'id' => $servicio->id,
            'activo' => true,
        ]);
    }

    public function test_eliminar_un_servicio_ajeno_devuelve_404(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        $servicioAjeno = $otroUsuario->servicios()->create([
            'nombre' => 'Nail Art',
            'duracion_minutos' => 45,
            'precio' => 2000,
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/servicios/{$servicioAjeno->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('servicios', ['id' => $servicioAjeno->id]);
    }
}

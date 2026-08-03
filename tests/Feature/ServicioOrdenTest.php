<?php

namespace Tests\Feature;

use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicioOrdenTest extends TestCase
{
    use RefreshDatabase;

    public function test_crear_un_servicio_asigna_el_proximo_orden_secuencial(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $primero = $this->actingAs($user, 'sanctum')
            ->postJson('/api/servicios', [
                'nombre' => 'Manicura clásica',
                'duracion_minutos' => 30,
                'precio' => 1000,
            ])
            ->assertCreated();

        $segundo = $this->actingAs($user, 'sanctum')
            ->postJson('/api/servicios', [
                'nombre' => 'Nail Art',
                'duracion_minutos' => 45,
                'precio' => 2000,
            ])
            ->assertCreated();

        $primero->assertJsonPath('orden', 0);
        $segundo->assertJsonPath('orden', 1);
    }

    public function test_index_devuelve_los_servicios_ordenados_por_orden_y_no_por_nombre(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $zeta = $user->servicios()->create([
            'nombre' => 'Zeta servicio',
            'duracion_minutos' => 30,
            'precio' => 1000,
            'orden' => 0,
        ]);
        $alfa = $user->servicios()->create([
            'nombre' => 'Alfa servicio',
            'duracion_minutos' => 30,
            'precio' => 1000,
            'orden' => 1,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/servicios')
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertSame([$zeta->id, $alfa->id], $ids);
    }

    public function test_reordenar_actualiza_el_orden_segun_la_lista_enviada(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $uno = $user->servicios()->create([
            'nombre' => 'Uno',
            'duracion_minutos' => 30,
            'precio' => 1000,
            'orden' => 0,
        ]);
        $dos = $user->servicios()->create([
            'nombre' => 'Dos',
            'duracion_minutos' => 30,
            'precio' => 1000,
            'orden' => 1,
        ]);
        $tres = $user->servicios()->create([
            'nombre' => 'Tres',
            'duracion_minutos' => 30,
            'precio' => 1000,
            'orden' => 2,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson('/api/servicios/reordenar', [
                'ids' => [$tres->id, $uno->id, $dos->id],
            ])
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertSame([$tres->id, $uno->id, $dos->id], $ids);

        $this->assertSame(0, $tres->fresh()->orden);
        $this->assertSame(1, $uno->fresh()->orden);
        $this->assertSame(2, $dos->fresh()->orden);
    }

    public function test_reordenar_rechaza_un_id_que_no_pertenece_al_usuario_autenticado(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        $propio = $user->servicios()->create([
            'nombre' => 'Propio',
            'duracion_minutos' => 30,
            'precio' => 1000,
            'orden' => 0,
        ]);
        $ajeno = $otroUsuario->servicios()->create([
            'nombre' => 'Ajeno',
            'duracion_minutos' => 30,
            'precio' => 1000,
            'orden' => 0,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/servicios/reordenar', [
                'ids' => [$propio->id, $ajeno->id],
            ])
            ->assertStatus(422);

        $this->assertSame(0, $ajeno->fresh()->orden);
    }

    public function test_reordenar_rechaza_ids_vacio_o_ausente(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/servicios/reordenar', [])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/servicios/reordenar', ['ids' => []])
            ->assertStatus(422);
    }
}

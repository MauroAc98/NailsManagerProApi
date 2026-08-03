<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicioUpdateAutoAttachProfesionalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_editar_un_servicio_huerfano_lo_asigna_a_todas_las_profesionales_activas(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $jefa = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $empleada = Profesional::create(['user_id' => $user->id, 'nombre' => 'Empleada', 'activo' => true]);
        $inactiva = Profesional::create(['user_id' => $user->id, 'nombre' => 'Inactiva', 'activo' => false]);

        // Servicio creado sin pasar por store() (simula uno pre-existente
        // al fix de auto-attach), por lo tanto sin ninguna profesional asignada.
        $servicio = Servicio::create([
            'user_id' => $user->id,
            'nombre' => 'Nail Art',
            'duracion_minutos' => 45,
            'precio' => 2000,
            'orden' => 0,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/servicios/{$servicio->id}", ['es_promo' => true])
            ->assertOk();

        $this->assertTrue($jefa->servicios()->where('servicios.id', $servicio->id)->exists());
        $this->assertTrue($empleada->servicios()->where('servicios.id', $servicio->id)->exists());
        $this->assertFalse($inactiva->servicios()->where('servicios.id', $servicio->id)->exists());
    }

    public function test_editar_un_servicio_con_asignacion_explicita_no_le_toca_las_profesionales(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $jefa = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $empleada = Profesional::create(['user_id' => $user->id, 'nombre' => 'Empleada', 'activo' => true]);

        $servicio = Servicio::create([
            'user_id' => $user->id,
            'nombre' => 'Nail Art',
            'duracion_minutos' => 45,
            'precio' => 2000,
            'orden' => 0,
        ]);

        // Restricción explícita: solo la jefa lo ofrece.
        $servicio->profesionales()->sync([$jefa->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/servicios/{$servicio->id}", ['es_promo' => true])
            ->assertOk();

        $this->assertTrue($jefa->servicios()->where('servicios.id', $servicio->id)->exists());
        $this->assertFalse($empleada->servicios()->where('servicios.id', $servicio->id)->exists());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfesionalUltimaActivaTest extends TestCase
{
    use RefreshDatabase;

    private function profesional(User $user, bool $activo = true): Profesional
    {
        return Profesional::create(['user_id' => $user->id, 'nombre' => 'Camila', 'activo' => $activo]);
    }

    public function test_update_no_permite_desactivar_a_la_ultima_activa(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $unica = $this->profesional($user);
        $this->profesional($user, false);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$unica->id}", ['activo' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('activo');

        $this->assertTrue($unica->fresh()->activo);
    }

    public function test_update_permite_desactivar_si_queda_otra_activa(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $a = $this->profesional($user);
        $this->profesional($user);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$a->id}", ['activo' => false])
            ->assertOk();

        $this->assertFalse($a->fresh()->activo);
    }

    public function test_las_activas_de_otro_salon_no_cuentan(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otro = User::factory()->create(['is_exempt' => true]);
        $unica = $this->profesional($user);
        $this->profesional($otro);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$unica->id}", ['activo' => false])
            ->assertStatus(422);
    }

    public function test_destroy_no_permite_desactivar_a_la_ultima_activa(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $unica = $this->profesional($user);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/profesionales/{$unica->id}")
            ->assertStatus(422);

        $this->assertTrue($unica->fresh()->activo);
    }

    public function test_update_de_una_inactiva_sin_tocar_activo_sigue_funcionando(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $this->profesional($user);
        $inactiva = $this->profesional($user, false);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$inactiva->id}", ['activo' => false, 'apellido' => 'Ríos'])
            ->assertOk();
    }
}

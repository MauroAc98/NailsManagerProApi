<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdatePerfilDireccionRequeridaTest extends TestCase
{
    use RefreshDatabase;

    public function test_activar_confirmacion_automatica_sin_direccion_es_rechazado(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'direccion' => null, 'confirmacion_automatica' => false]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', ['confirmacion_automatica' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('direccion');

        $this->assertFalse($user->fresh()->confirmacion_automatica);
    }

    public function test_activar_recordatorio_automatico_sin_direccion_es_rechazado(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'direccion' => null, 'recordatorio_automatico' => false]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', ['recordatorio_automatico' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('direccion');

        $this->assertFalse($user->fresh()->recordatorio_automatico);
    }

    public function test_activar_confirmacion_automatica_con_direccion_ya_cargada_se_permite(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'direccion' => 'Av. Siempre Viva 742', 'confirmacion_automatica' => false]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', ['confirmacion_automatica' => true])
            ->assertOk();

        $this->assertTrue($user->fresh()->confirmacion_automatica);
    }

    public function test_activar_confirmacion_automatica_cargando_direccion_en_el_mismo_request_se_permite(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'direccion' => null, 'confirmacion_automatica' => false]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', ['confirmacion_automatica' => true, 'direccion' => 'Av. Siempre Viva 742'])
            ->assertOk();

        $this->assertTrue($user->fresh()->confirmacion_automatica);
        $this->assertSame('Av. Siempre Viva 742', $user->fresh()->direccion);
    }

    public function test_actualizar_otro_campo_sin_tocar_direccion_no_dispara_el_guard_si_los_automaticos_estan_apagados(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'direccion' => null, 'confirmacion_automatica' => false, 'recordatorio_automatico' => false]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', ['telefono' => '3765000000'])
            ->assertOk();

        $this->assertSame('3765000000', $user->fresh()->telefono);
    }

    public function test_actualizar_campo_no_relacionado_no_dispara_el_guard_aunque_confirmacion_automatica_este_true_por_default(): void
    {
        // Regresión: confirmacion_automatica es true por default en cuentas
        // nuevas (User::$attributes) — el guard NO debe dispararse por eso
        // solo, sino que la request tiene que estar tocando alguno de los
        // dos toggles. Antes de este fix, una cuenta nueva sin dirección
        // no podía ni cambiar el locale.
        $user = User::factory()->create(['is_exempt' => true, 'direccion' => null]);
        $this->assertTrue($user->confirmacion_automatica);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', ['locale' => 'pt-BR'])
            ->assertOk();

        $this->assertSame('pt-BR', $user->fresh()->locale);
    }
}

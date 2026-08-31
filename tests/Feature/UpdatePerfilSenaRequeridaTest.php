<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdatePerfilSenaRequeridaTest extends TestCase
{
    use RefreshDatabase;

    private function userConSenaCompleta(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'is_exempt' => true,
            'direccion' => 'Av. Siempre Viva 742',
            'whatsapp_pide_sena' => true,
            'sena_monto' => 5000,
            'whatsapp_sena_titular' => 'Kimberley Faustino',
            'whatsapp_sena_entidad' => 'Banco Macro SA',
            'whatsapp_sena_alias' => 'Kim1710',
            'whatsapp_sena_cbu' => null,
        ], $overrides));
    }

    public function test_activar_sena_con_datos_completos_se_permite(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'whatsapp_pide_sena' => false]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'whatsapp_pide_sena' => true,
                'sena_monto' => 5000,
                'whatsapp_sena_titular' => 'Kimberley Faustino',
                'whatsapp_sena_alias' => 'Kim1710',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue($fresh->whatsapp_pide_sena);
        $this->assertSame('Kimberley Faustino', $fresh->whatsapp_sena_titular);
        $this->assertSame('Kim1710', $fresh->whatsapp_sena_alias);
    }

    public function test_activar_sena_sin_monto_es_rechazado(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'whatsapp_pide_sena' => false, 'sena_monto' => null]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'whatsapp_pide_sena' => true,
                'whatsapp_sena_titular' => 'Kimberley Faustino',
                'whatsapp_sena_alias' => 'Kim1710',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sena_monto');

        $this->assertFalse($user->fresh()->whatsapp_pide_sena);
    }

    public function test_activar_sena_sin_titular_es_rechazado(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'whatsapp_pide_sena' => false]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'whatsapp_pide_sena' => true,
                'sena_monto' => 5000,
                'whatsapp_sena_alias' => 'Kim1710',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('whatsapp_sena_titular');
    }

    public function test_activar_sena_sin_alias_ni_cbu_es_rechazado(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'whatsapp_pide_sena' => false]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'whatsapp_pide_sena' => true,
                'sena_monto' => 5000,
                'whatsapp_sena_titular' => 'Kimberley Faustino',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('whatsapp_sena_alias');
    }

    public function test_borrar_el_unico_medio_de_pago_de_un_salon_activo_es_rechazado(): void
    {
        $user = $this->userConSenaCompleta(); // alias Kim1710, sin cbu

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', ['whatsapp_sena_alias' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('whatsapp_sena_alias');

        $this->assertSame('Kim1710', $user->fresh()->whatsapp_sena_alias);
    }

    public function test_apagar_el_toggle_no_borra_los_datos_bancarios(): void
    {
        $user = $this->userConSenaCompleta();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', ['whatsapp_pide_sena' => false])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertFalse($fresh->whatsapp_pide_sena);
        $this->assertSame('Kimberley Faustino', $fresh->whatsapp_sena_titular);
        $this->assertSame('Kim1710', $fresh->whatsapp_sena_alias);
        $this->assertSame('5000.00', (string) $fresh->sena_monto);
    }

    public function test_editar_un_campo_no_relacionado_no_dispara_el_guard_de_sena(): void
    {
        $user = $this->userConSenaCompleta();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', ['locale' => 'pt-BR'])
            ->assertOk();

        $this->assertSame('pt-BR', $user->fresh()->locale);
    }
}

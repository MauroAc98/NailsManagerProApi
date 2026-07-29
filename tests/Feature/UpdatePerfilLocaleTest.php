<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdatePerfilLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_locale_valido_se_persiste_y_se_devuelve(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', ['locale' => 'pt-BR'])
            ->assertOk();

        $response->assertJsonPath('locale', 'pt-BR');
        $this->assertSame('pt-BR', $user->fresh()->locale);
    }

    public function test_locale_invalido_es_rechazado_y_no_se_persiste(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'locale' => 'es']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', ['locale' => 'fr'])
            ->assertStatus(422);

        $this->assertSame('es', $user->fresh()->locale);
    }

    public function test_locale_ausente_resuelve_a_null_en_me(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('locale', null);
    }
}

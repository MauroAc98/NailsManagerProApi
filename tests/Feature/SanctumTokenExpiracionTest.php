<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los tokens de los negocios no vencian nunca: un token filtrado (celular
 * perdido, PWA robada) servia para siempre hasta el proximo login. Ahora
 * vencen a los 90 dias de emitidos.
 */
class SanctumTokenExpiracionTest extends TestCase
{
    use RefreshDatabase;

    private function tokenEmitidoHaceDias(User $user, int $dias): string
    {
        $nuevo = $user->createToken('app-mobile');
        $nuevo->accessToken->forceFill(['created_at' => now()->subDays($dias)])->save();

        return $nuevo->plainTextToken;
    }

    public function test_un_token_de_89_dias_sigue_funcionando(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->withToken($this->tokenEmitidoHaceDias($user, 89))
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_un_token_de_91_dias_ya_no_sirve(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->withToken($this->tokenEmitidoHaceDias($user, 91))
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_el_vencimiento_configurado_es_de_90_dias(): void
    {
        $this->assertSame(60 * 24 * 90, config('sanctum.expiration'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_desconectar_corta_despues_del_limite_por_minuto(): void
    {
        Http::fake([
            '*/instance/logout/*' => Http::response([], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user, 'sanctum')
                ->deleteJson('/api/whatsapp/desconectar')
                ->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/whatsapp/desconectar')
            ->assertStatus(429);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappEstadoHistorial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappEstadoPollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_registra_historial_cuando_el_polling_detecta_un_cambio(): void
    {
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectando',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/whatsapp/estado')
            ->assertOk()
            ->assertJson(['estado' => 'conectado']);

        $this->assertDatabaseHas('whatsapp_estado_historiales', [
            'user_id' => $user->id,
            'estado' => 'conectado',
        ]);
    }

    public function test_no_registra_historial_cuando_el_polling_no_detecta_cambio(): void
    {
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/whatsapp/estado')
            ->assertOk();

        $this->assertSame(
            0,
            WhatsappEstadoHistorial::where('user_id', $user->id)->count(),
        );
    }
}

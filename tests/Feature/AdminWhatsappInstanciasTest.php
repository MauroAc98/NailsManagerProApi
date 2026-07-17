<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappEstadoHistorial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWhatsappInstanciasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.admin_secret' => 'secreto-de-test']);
    }

    public function test_rechaza_sin_el_header_correcto(): void
    {
        $this->getJson('/api/admin/whatsapp/instancias')
            ->assertStatus(401);
    }

    public function test_lista_instancias_con_estado_actual(): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        User::factory()->create(['evolution_instance_name' => null]);

        $response = $this->withHeader('X-Admin-Secret', 'secreto-de-test')
            ->getJson('/api/admin/whatsapp/instancias')
            ->assertOk();

        $response->assertJsonCount(1, 'instancias');
        $response->assertJsonFragment([
            'user_id' => $user->id,
            'instance_name' => 'user_1',
            'estado' => 'conectado',
        ]);
    }

    public function test_devuelve_historial_de_una_instancia(): void
    {
        $user = User::factory()->create(['evolution_instance_name' => 'user_1']);

        WhatsappEstadoHistorial::create([
            'user_id' => $user->id,
            'estado' => 'conectado',
            'status_reason' => null,
        ]);

        $response = $this->withHeader('X-Admin-Secret', 'secreto-de-test')
            ->getJson("/api/admin/whatsapp/instancias/{$user->id}/historial")
            ->assertOk();

        $response->assertJsonFragment(['estado' => 'conectado']);
    }
}

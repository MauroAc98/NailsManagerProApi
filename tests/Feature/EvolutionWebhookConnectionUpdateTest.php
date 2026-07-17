<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappEstadoHistorial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvolutionWebhookConnectionUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function postConnectionUpdate(string $instanceName, ?string $state, ?int $statusReason): void
    {
        $this->postJson('/api/webhooks/evolution', [
            'event' => 'connection.update',
            'instance' => $instanceName,
            'data' => array_filter([
                'state' => $state,
                'statusReason' => $statusReason,
            ], fn ($value) => $value !== null),
        ])->assertOk();
    }

    public function test_marca_conectado_cuando_state_es_open(): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectando',
        ]);

        $this->postConnectionUpdate('user_1', 'open', null);

        $this->assertSame('conectado', $user->fresh()->whatsapp_estado);
    }

    /**
     * @dataProvider fallosTerminalesProvider
     */
    public function test_marca_desconectado_para_fallos_terminales_de_baileys(int $statusReason): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectando',
        ]);

        $this->postConnectionUpdate('user_1', 'connecting', $statusReason);

        $this->assertSame('desconectado', $user->fresh()->whatsapp_estado);
    }

    public function test_registra_historial_cuando_el_estado_cambia(): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectando',
        ]);

        $this->postConnectionUpdate('user_1', 'open', null);

        $this->assertDatabaseHas('whatsapp_estado_historiales', [
            'user_id' => $user->id,
            'estado' => 'conectado',
            'status_reason' => null,
        ]);
    }

    public function test_no_duplica_historial_cuando_el_estado_no_cambia(): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        $this->postConnectionUpdate('user_1', 'open', null);

        $this->assertSame(
            0,
            WhatsappEstadoHistorial::where('user_id', $user->id)->count(),
        );
    }

    public static function fallosTerminalesProvider(): array
    {
        return [
            'loggedOut/device_removed' => [401],
            'timedOut' => [408],
            'connectionClosed' => [428],
            'connectionReplaced' => [440],
            'restartRequired' => [515],
        ];
    }

    public function test_mantiene_conectando_para_statusReason_desconocido(): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectando',
        ]);

        $this->postConnectionUpdate('user_1', 'connecting', 200);

        $this->assertSame('conectando', $user->fresh()->whatsapp_estado);
    }
}

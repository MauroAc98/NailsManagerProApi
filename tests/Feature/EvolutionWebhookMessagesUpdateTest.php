<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EvolutionWebhookMessagesUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-de-test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.evolution.webhook_secret' => self::SECRETO]);
    }

    private function postMessagesUpdate(string $instanceName, string $messageId, int|string $status, ?array $stubParameters = null): TestResponse
    {
        return $this->postJson('/api/webhooks/evolution/'.self::SECRETO, [
            'event' => 'messages.update',
            'instance' => $instanceName,
            'data' => [
                'key' => [
                    'id' => $messageId,
                    'remoteJid' => '5493765252395@s.whatsapp.net',
                    'fromMe' => true,
                ],
                'update' => array_filter([
                    'status' => $status,
                    'messageStubParameters' => $stubParameters,
                ], fn ($value) => $value !== null),
            ],
        ]);
    }

    public function test_marca_delivered_cuando_status_es_delivery_ack(): void
    {
        User::factory()->create(['evolution_instance_name' => 'user_1']);

        $mensaje = WhatsappMensaje::create([
            'user_id' => User::first()->id,
            'numero' => '5493765252395',
            'mensaje' => 'Hola',
            'tipo' => 'confirmacion',
            'message_id' => 'MSG-1',
            'status' => 'pending',
        ]);

        $this->postMessagesUpdate('user_1', 'MSG-1', 3)->assertOk();

        $this->assertSame('delivered', $mensaje->fresh()->status);
    }

    public function test_marca_failed_cuando_whatsapp_devuelve_status_error(): void
    {
        $user = User::factory()->create(['evolution_instance_name' => 'user_1']);

        // WhatsApp devuelve status=0 (ERROR de Baileys) cuando el mensaje
        // no pudo entregarse de verdad. Antes de este fix, "! $status"
        // trataba el 0 como valor ausente y el registro quedaba "pending"
        // para siempre, sin log ni forma de detectarlo.
        $mensaje = WhatsappMensaje::create([
            'user_id' => $user->id,
            'numero' => '5493765252395',
            'mensaje' => 'Hola',
            'tipo' => 'confirmacion',
            'message_id' => 'MSG-2',
            'status' => 'pending',
        ]);

        $this->postMessagesUpdate('user_1', 'MSG-2', 0, ['463'])->assertOk();

        $this->assertSame('failed', $mensaje->fresh()->status);
    }

    public function test_marca_failed_cuando_status_es_el_string_error(): void
    {
        $user = User::factory()->create(['evolution_instance_name' => 'user_1']);

        $mensaje = WhatsappMensaje::create([
            'user_id' => $user->id,
            'numero' => '5493765252395',
            'mensaje' => 'Hola',
            'tipo' => 'confirmacion',
            'message_id' => 'MSG-3',
            'status' => 'pending',
        ]);

        $this->postMessagesUpdate('user_1', 'MSG-3', 'ERROR')->assertOk();

        $this->assertSame('failed', $mensaje->fresh()->status);
    }

    public function test_ignora_actualizaciones_de_mensajes_que_no_son_nuestros(): void
    {
        $this->postMessagesUpdate('user_1', 'MSG-DESCONOCIDO', 3)->assertOk();

        $this->assertSame(0, WhatsappMensaje::count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CloudApiWebhookHandleTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'app-secret-de-test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp_cloud.app_secret' => self::APP_SECRET]);
    }

    private function postSigned(array $payload, ?string $secret = null): TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret ?? self::APP_SECRET);

        return $this->call('POST', '/api/webhooks/whatsapp-cloud', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function statusPayload(string $messageId, string $status, array $errors = [], ?int $timestamp = null): array
    {
        $statusEntry = [
            'id' => $messageId,
            'status' => $status,
            'timestamp' => (string) ($timestamp ?? now()->timestamp),
            'recipient_id' => '5493765123456',
        ];

        if ($errors) {
            $statusEntry['errors'] = $errors;
        }

        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-id',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => '123'],
                        'statuses' => [$statusEntry],
                    ],
                ]],
            ]],
        ];
    }

    private function crearMensaje(User $user, string $messageId, string $status = 'pending'): WhatsappMensaje
    {
        return WhatsappMensaje::create([
            'user_id' => $user->id,
            'numero' => '5493765123456',
            'mensaje' => 'texto',
            'tipo' => 'confirmacion',
            'message_id' => $messageId,
            'status' => $status,
        ]);
    }

    public function test_actualiza_a_delivered(): void
    {
        $user = User::factory()->create();
        $mensaje = $this->crearMensaje($user, 'wamid.ABC123');

        $this->postSigned($this->statusPayload('wamid.ABC123', 'delivered'))->assertOk();

        $this->assertSame('delivered', $mensaje->fresh()->status);
    }

    public function test_actualiza_a_read(): void
    {
        $user = User::factory()->create();
        $mensaje = $this->crearMensaje($user, 'wamid.ABC456', 'delivered');

        $this->postSigned($this->statusPayload('wamid.ABC456', 'read'))->assertOk();

        $this->assertSame('read', $mensaje->fresh()->status);
    }

    public function test_actualiza_a_failed_con_errores(): void
    {
        $user = User::factory()->create();
        $mensaje = $this->crearMensaje($user, 'wamid.ABC789');

        $this->postSigned($this->statusPayload('wamid.ABC789', 'failed', [
            ['code' => 131026, 'title' => 'Message undeliverable'],
        ]))->assertOk();

        $this->assertSame('failed', $mensaje->fresh()->status);
    }

    public function test_firma_invalida_devuelve_404_y_no_toca_el_registro(): void
    {
        $user = User::factory()->create();
        $mensaje = $this->crearMensaje($user, 'wamid.ABC999');

        $this->postSigned($this->statusPayload('wamid.ABC999', 'delivered'), secret: 'secreto-incorrecto')
            ->assertStatus(404);

        $this->assertSame('pending', $mensaje->fresh()->status);
    }

    public function test_sin_header_de_firma_devuelve_404(): void
    {
        $body = json_encode($this->statusPayload('wamid.NOPE', 'delivered'));

        $response = $this->call('POST', '/api/webhooks/whatsapp-cloud', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertStatus(404);
    }

    public function test_message_id_desconocido_no_revienta_y_devuelve_200(): void
    {
        $this->postSigned($this->statusPayload('wamid.NO-EXISTE', 'delivered'))
            ->assertOk();

        $this->assertSame(0, WhatsappMensaje::count());
    }

    public function test_un_status_sent_que_llega_tarde_no_hace_retroceder_un_read_ya_procesado(): void
    {
        $user = User::factory()->create();
        $mensaje = $this->crearMensaje($user, 'wamid.FUERA-DE-ORDEN');

        $ahora = now()->timestamp;

        // 'read' llega primero (cronológicamente más nuevo, timestamp +20s)
        $this->postSigned($this->statusPayload('wamid.FUERA-DE-ORDEN', 'read', timestamp: $ahora + 20))
            ->assertOk();
        $this->assertSame('read', $mensaje->fresh()->status);

        // 'sent' (-> 'pending' en nuestro mapeo) llega DESPUÉS en el tiempo
        // de red, pero es cronológicamente más VIEJO (timestamp +5s) —
        // reintento/cola fuera de orden de Meta. No debe pisar el 'read'.
        $this->postSigned($this->statusPayload('wamid.FUERA-DE-ORDEN', 'sent', timestamp: $ahora + 5))
            ->assertOk();

        $this->assertSame('read', $mensaje->fresh()->status);
    }

    public function test_status_events_en_orden_cronologico_se_aplican_normalmente(): void
    {
        $user = User::factory()->create();
        $mensaje = $this->crearMensaje($user, 'wamid.EN-ORDEN');

        $ahora = now()->timestamp;

        $this->postSigned($this->statusPayload('wamid.EN-ORDEN', 'delivered', timestamp: $ahora))
            ->assertOk();
        $this->assertSame('delivered', $mensaje->fresh()->status);

        $this->postSigned($this->statusPayload('wamid.EN-ORDEN', 'read', timestamp: $ahora + 10))
            ->assertOk();
        $this->assertSame('read', $mensaje->fresh()->status);
    }

    public function test_payload_con_messages_entrantes_no_tira_excepcion(): void
    {
        // Scope actual: con un solo número Cloud API compartido para todo el
        // SaaS, todavía no está decidido cómo desambiguar a qué profesional/
        // cliente pertenece un mensaje entrante — se ignora deliberadamente
        // hasta que eso se resuelva (antes de migrar más profesionales).
        $user = User::factory()->create();
        $mensaje = $this->crearMensaje($user, 'wamid.MIXED');

        $payload = $this->statusPayload('wamid.MIXED', 'delivered');
        $payload['entry'][0]['changes'][0]['value']['messages'] = [[
            'from' => '5493765123456',
            'id' => 'wamid.INCOMING',
            'text' => ['body' => 'hola'],
            'type' => 'text',
        ]];

        $this->postSigned($payload)->assertOk();

        $this->assertSame('delivered', $mensaje->fresh()->status);
    }
}

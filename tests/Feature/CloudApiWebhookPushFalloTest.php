<?php

namespace Tests\Feature;

use App\Jobs\EnviarPushFalloWhatsapp;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CloudApiWebhookPushFalloTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'app-secret-de-test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp_cloud.app_secret' => self::APP_SECRET]);
    }

    private function postStatus(string $messageId, string $status, ?int $timestamp = null): TestResponse
    {
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-id',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => '123'],
                        'statuses' => [[
                            'id' => $messageId,
                            'status' => $status,
                            'timestamp' => (string) ($timestamp ?? now()->timestamp),
                            'recipient_id' => '5493765123456',
                        ]],
                    ],
                ]],
            ]],
        ]);

        return $this->call('POST', '/api/webhooks/whatsapp-cloud', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, self::APP_SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function crearMensaje(string $messageId, string $status = 'pending'): WhatsappMensaje
    {
        return WhatsappMensaje::create([
            'user_id' => User::factory()->create()->id,
            'numero' => '5493765123456',
            'mensaje' => 'texto',
            'tipo' => 'confirmacion',
            'message_id' => $messageId,
            'status' => $status,
        ]);
    }

    public function test_a_failed_status_dispatches_the_push_job_once(): void
    {
        Queue::fake();
        $m = $this->crearMensaje('wamid.F1');

        $this->postStatus('wamid.F1', 'failed')->assertOk();

        Queue::assertPushed(EnviarPushFalloWhatsapp::class, 1);
        Queue::assertPushed(EnviarPushFalloWhatsapp::class, fn ($job) => $job->mensajeId === $m->id);
    }

    public function test_a_repeated_failed_status_does_not_dispatch_again(): void
    {
        Queue::fake();
        $this->crearMensaje('wamid.F2', 'failed');

        $this->postStatus('wamid.F2', 'failed')->assertOk();

        Queue::assertNotPushed(EnviarPushFalloWhatsapp::class);
    }

    public function test_the_same_failed_event_sent_twice_dispatches_only_once(): void
    {
        Queue::fake();
        $this->crearMensaje('wamid.F3');
        $ts = now()->timestamp;

        $this->postStatus('wamid.F3', 'failed', $ts)->assertOk();
        $this->postStatus('wamid.F3', 'failed', $ts)->assertOk();

        Queue::assertPushed(EnviarPushFalloWhatsapp::class, 1);
    }

    public function test_other_statuses_do_not_dispatch(): void
    {
        Queue::fake();
        $this->crearMensaje('wamid.S1');
        $this->crearMensaje('wamid.S2', 'delivered');
        $this->crearMensaje('wamid.S3', 'delivered');

        $this->postStatus('wamid.S1', 'sent')->assertOk();
        $this->postStatus('wamid.S2', 'delivered')->assertOk();
        $this->postStatus('wamid.S3', 'read')->assertOk();

        Queue::assertNotPushed(EnviarPushFalloWhatsapp::class);
    }

    public function test_an_unknown_message_id_does_not_dispatch(): void
    {
        Queue::fake();

        $this->postStatus('wamid.NOT-OURS', 'failed')->assertOk();

        Queue::assertNotPushed(EnviarPushFalloWhatsapp::class);
    }

    public function test_a_failed_status_discarded_as_out_of_order_does_not_dispatch(): void
    {
        Queue::fake();
        $m = $this->crearMensaje('wamid.OLD');
        $m->update(['status_event_at' => now()->timestamp]);

        $this->postStatus('wamid.OLD', 'failed', now()->subHour()->timestamp)->assertOk();

        $this->assertSame('pending', $m->fresh()->status);
        Queue::assertNotPushed(EnviarPushFalloWhatsapp::class);
    }

    public function test_a_dispatch_exception_does_not_change_the_200_nor_the_persisted_status(): void
    {
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->andReturnUsing(function ($job) {
                if ($job instanceof EnviarPushFalloWhatsapp) {
                    throw new \RuntimeException('queue down');
                }

                return 0;
            });
        });
        $m = $this->crearMensaje('wamid.F4');

        $this->postStatus('wamid.F4', 'failed')->assertOk();

        $this->assertSame('failed', $m->fresh()->status);
    }
}

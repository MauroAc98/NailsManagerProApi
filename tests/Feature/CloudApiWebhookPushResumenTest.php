<?php

namespace Tests\Feature;

use App\Jobs\EnviarPushResumenWhatsapp;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CloudApiWebhookPushResumenTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'app-secret-de-test';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp_cloud.app_secret' => self::APP_SECRET]);
        $this->user = User::factory()->create();
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

    private function crearMensaje(string $messageId, string $tipo = 'confirmacion', string $status = 'pending', ?User $user = null): WhatsappMensaje
    {
        return WhatsappMensaje::create([
            'user_id' => ($user ?? $this->user)->id,
            'numero' => '5493765123456',
            'mensaje' => 'texto',
            'tipo' => $tipo,
            'message_id' => $messageId,
            'status' => $status,
        ]);
    }

    public function test_delivered_dispatches_the_summary_job_with_category_and_delay(): void
    {
        Queue::fake();
        $this->crearMensaje('wamid.C1', 'confirmacion');

        $this->postStatus('wamid.C1', 'delivered')->assertOk();

        Queue::assertPushed(EnviarPushResumenWhatsapp::class, 1);
        Queue::assertPushed(EnviarPushResumenWhatsapp::class, function (EnviarPushResumenWhatsapp $job) {
            $segundos = now()->diffInSeconds($job->delay, false);

            return $job->userId === $this->user->id
                && $job->categoria === 'confirmaciones'
                && $segundos > 25 && $segundos <= 30;
        });
    }

    public function test_reprogramacion_counts_as_confirmaciones(): void
    {
        Queue::fake();
        $this->crearMensaje('wamid.R1', 'reprogramacion');

        $this->postStatus('wamid.R1', 'delivered')->assertOk();

        Queue::assertPushed(EnviarPushResumenWhatsapp::class, fn ($job) => $job->categoria === 'confirmaciones');
    }

    public function test_recordatorio_maps_to_recordatorios(): void
    {
        Queue::fake();
        $this->crearMensaje('wamid.REC1', 'recordatorio');

        $this->postStatus('wamid.REC1', 'delivered')->assertOk();

        Queue::assertPushed(EnviarPushResumenWhatsapp::class, 1);
        Queue::assertPushed(EnviarPushResumenWhatsapp::class, fn ($job) => $job->categoria === 'recordatorios');
    }

    public function test_read_straight_from_pending_also_requests_the_summary(): void
    {
        Queue::fake();
        $this->crearMensaje('wamid.RD1', 'confirmacion');

        $this->postStatus('wamid.RD1', 'read')->assertOk();

        Queue::assertPushed(EnviarPushResumenWhatsapp::class, 1);
    }

    public function test_a_burst_of_delivered_events_dispatches_once(): void
    {
        Queue::fake();
        foreach (range(1, 5) as $i) {
            $this->crearMensaje('wamid.B' . $i, 'confirmacion');
        }

        foreach (range(1, 5) as $i) {
            $this->postStatus('wamid.B' . $i, 'delivered')->assertOk();
        }

        Queue::assertPushed(EnviarPushResumenWhatsapp::class, 1);
    }

    public function test_each_user_and_category_gets_its_own_window(): void
    {
        Queue::fake();
        $otra = User::factory()->create();
        $this->crearMensaje('wamid.U1', 'confirmacion');
        $this->crearMensaje('wamid.U2', 'recordatorio');
        $this->crearMensaje('wamid.U3', 'confirmacion', 'pending', $otra);

        $this->postStatus('wamid.U1', 'delivered')->assertOk();
        $this->postStatus('wamid.U2', 'delivered')->assertOk();
        $this->postStatus('wamid.U3', 'delivered')->assertOk();

        Queue::assertPushed(EnviarPushResumenWhatsapp::class, 3);
    }

    public function test_read_after_delivered_does_not_dispatch_again(): void
    {
        Queue::fake();
        $this->crearMensaje('wamid.D1', 'confirmacion', 'delivered');

        $this->postStatus('wamid.D1', 'read')->assertOk();

        Queue::assertNotPushed(EnviarPushResumenWhatsapp::class);
    }

    public function test_a_repeated_delivered_event_does_not_dispatch_again(): void
    {
        Queue::fake();
        $this->crearMensaje('wamid.D2', 'confirmacion', 'delivered');

        $this->postStatus('wamid.D2', 'delivered')->assertOk();

        Queue::assertNotPushed(EnviarPushResumenWhatsapp::class);
    }

    public function test_pending_and_failed_do_not_dispatch(): void
    {
        Queue::fake();
        $this->crearMensaje('wamid.P1', 'confirmacion');
        $this->crearMensaje('wamid.P2', 'confirmacion');

        $this->postStatus('wamid.P1', 'sent')->assertOk();
        $this->postStatus('wamid.P2', 'failed')->assertOk();

        Queue::assertNotPushed(EnviarPushResumenWhatsapp::class);
    }

    public function test_other_tipos_do_not_dispatch(): void
    {
        Queue::fake();
        $this->crearMensaje('wamid.O1', 'cancelacion');
        $this->crearMensaje('wamid.O2', 'otro');

        $this->postStatus('wamid.O1', 'delivered')->assertOk();
        $this->postStatus('wamid.O2', 'delivered')->assertOk();

        Queue::assertNotPushed(EnviarPushResumenWhatsapp::class);
    }

    public function test_an_unknown_message_id_does_not_dispatch(): void
    {
        Queue::fake();

        $this->postStatus('wamid.NOT-OURS', 'delivered')->assertOk();

        Queue::assertNotPushed(EnviarPushResumenWhatsapp::class);
    }

    public function test_a_dispatch_exception_does_not_change_the_200_nor_the_persisted_status(): void
    {
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->andReturnUsing(function ($job) {
                if ($job instanceof EnviarPushResumenWhatsapp) {
                    throw new \RuntimeException('queue down');
                }

                return 0;
            });
        });
        $m = $this->crearMensaje('wamid.X1', 'confirmacion');

        $this->postStatus('wamid.X1', 'delivered')->assertOk();

        $this->assertSame('delivered', $m->fresh()->status);
    }
}

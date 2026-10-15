<?php

namespace Tests\Feature;

use App\Jobs\EnviarPushResumenWhatsapp;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Notifications\ResumenMensajesWhatsapp;
use App\Services\Push\ResumenWhatsappPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ResumenWhatsappPushTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function suscribir(): void
    {
        $this->user->updatePushSubscription('https://push.example/a', 'k', 't');
    }

    private function mensajes(int $cantidad, string $tipo, string $status, ?User $user = null, bool $deAyer = false): void
    {
        foreach (range(1, $cantidad) as $i) {
            $m = WhatsappMensaje::create([
                'user_id' => ($user ?? $this->user)->id,
                'numero' => '5493765252395',
                'mensaje' => 'texto',
                'tipo' => $tipo,
                'message_id' => 'wamid.' . uniqid('', true),
                'status' => $status,
            ]);

            if ($deAyer) {
                $m->forceFill(['created_at' => now()->subDay()])->save();
            }
        }
    }

    private function ejecutar(string $categoria = 'confirmaciones'): void
    {
        (new EnviarPushResumenWhatsapp($this->user->id, $categoria))->handle();
    }

    private function payloadEnviado(): array
    {
        $payload = null;
        Notification::assertSentTo($this->user, ResumenMensajesWhatsapp::class, function (ResumenMensajesWhatsapp $n) use (&$payload) {
            $payload = $n->payload;

            return true;
        });

        return $payload;
    }

    // -- Job ----------------------------------------------------------

    public function test_one_delivered_message_is_singular_and_silent(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(1, 'confirmacion', 'delivered');

        $this->ejecutar();

        Notification::assertSentToTimes($this->user, ResumenMensajesWhatsapp::class, 1);
        $p = $this->payloadEnviado();
        $this->assertSame('Confirmaciones enviadas', $p['title']);
        $this->assertSame('1 entregado', $p['body']);
        $this->assertSame('/agenda', $p['url']);
        $this->assertSame('resumen-whatsapp-confirmaciones-' . now()->toDateString(), $p['tag']);
        $this->assertTrue($p['silencioso']);
        $this->assertIsInt($p['timestamp']);
        $this->assertSame(['title', 'body', 'url', 'tag', 'timestamp', 'silencioso'], array_keys($p));
    }

    public function test_many_delivered_messages_are_plural_and_read_counts_as_delivered(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(3, 'confirmacion', 'delivered');
        $this->mensajes(2, 'reprogramacion', 'read');

        $this->ejecutar();

        $this->assertSame('5 entregados', $this->payloadEnviado()['body']);
    }

    public function test_failures_are_appended_with_singular_and_plural(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(4, 'confirmacion', 'delivered');
        $this->mensajes(1, 'confirmacion', 'failed');

        $this->ejecutar();
        $this->assertSame('4 entregados · 1 falló', $this->payloadEnviado()['body']);

        $this->mensajes(1, 'confirmacion', 'failed');
        Notification::fake();

        $this->ejecutar();
        $this->assertSame('4 entregados · 2 fallaron', $this->payloadEnviado()['body']);
    }

    public function test_one_delivered_with_failures_keeps_the_singular(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(1, 'recordatorio', 'delivered');
        $this->mensajes(3, 'recordatorio', 'failed');

        $this->ejecutar('recordatorios');

        $p = $this->payloadEnviado();
        $this->assertSame('Recordatorios enviados', $p['title']);
        $this->assertSame('1 entregado · 3 fallaron', $p['body']);
        $this->assertSame('resumen-whatsapp-recordatorios-' . now()->toDateString(), $p['tag']);
    }

    public function test_it_counts_only_the_requested_category(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(2, 'confirmacion', 'delivered');
        $this->mensajes(7, 'recordatorio', 'delivered');
        $this->mensajes(4, 'cancelacion', 'delivered');
        $this->mensajes(1, 'recordatorio', 'failed');

        $this->ejecutar('confirmaciones');

        $this->assertSame('2 entregados', $this->payloadEnviado()['body']);
    }

    public function test_it_counts_only_todays_messages_and_only_this_user(): void
    {
        Notification::fake();
        $this->suscribir();
        $otra = User::factory()->create();
        $this->mensajes(2, 'confirmacion', 'delivered');
        $this->mensajes(5, 'confirmacion', 'delivered', null, true);
        $this->mensajes(1, 'confirmacion', 'failed', null, true);
        $this->mensajes(6, 'confirmacion', 'delivered', $otra);

        $this->ejecutar();

        $this->assertSame('2 entregados', $this->payloadEnviado()['body']);
    }

    public function test_nothing_is_sent_when_nothing_was_delivered(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(3, 'confirmacion', 'failed');
        $this->mensajes(2, 'confirmacion', 'pending');
        $this->mensajes(4, 'confirmacion', 'delivered', null, true);

        $this->ejecutar();

        Notification::assertNothingSent();
    }

    public function test_nothing_is_sent_without_subscriptions(): void
    {
        Notification::fake();
        $this->mensajes(2, 'confirmacion', 'delivered');

        $this->ejecutar();

        Notification::assertNothingSent();
    }

    public function test_a_missing_user_or_unknown_category_is_a_no_op(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(2, 'confirmacion', 'delivered');

        (new EnviarPushResumenWhatsapp(999999, 'confirmaciones'))->handle();
        $this->ejecutar('inexistente');

        Notification::assertNothingSent();
    }

    // -- Notification -------------------------------------------------

    public function test_notification_uses_a_six_hour_ttl_and_normal_urgency(): void
    {
        $payload = ResumenWhatsappPayload::para('confirmaciones', 1, 0);
        $n = new ResumenMensajesWhatsapp($payload);

        $message = $n->toWebPush($this->user, $n);

        $this->assertSame(['TTL' => 21600, 'urgency' => 'normal'], $message->getOptions());
        $this->assertSame($payload, $message->toArray());
    }
}

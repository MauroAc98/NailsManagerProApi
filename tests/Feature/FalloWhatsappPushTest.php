<?php

namespace Tests\Feature;

use App\Jobs\EnviarPushFalloWhatsapp;
use App\Models\Cliente;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Notifications\FalloMensajeWhatsapp;
use App\Services\Push\FalloWhatsappPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FalloWhatsappPushTest extends TestCase
{
    use RefreshDatabase;

    // 2099-06-11 is a Thursday.
    private const FECHA = '2099-06-11';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function servicio(string $nombre): Servicio
    {
        return Servicio::create([
            'user_id' => $this->user->id, 'nombre' => $nombre, 'precio' => 1000, 'duracion_minutos' => 60,
        ]);
    }

    private function turno(string $fechaHora, array $servicios, string $nombre = 'Camila', ?string $apellido = 'Rodriguez'): Turno
    {
        $cliente = Cliente::create([
            'user_id' => $this->user->id, 'nombre' => $nombre, 'apellido' => $apellido, 'telefono' => '+5493765252395',
        ]);
        $turno = Turno::create([
            'user_id' => $this->user->id, 'cliente_id' => $cliente->id,
            'fecha_hora' => $fechaHora, 'duracion_total_minutos' => 60, 'estado' => 'confirmado', 'origen' => 'app',
        ]);
        $turno->servicios()->attach(array_map(fn (Servicio $s) => $s->id, $servicios));

        return $turno;
    }

    private function mensaje(?Turno $turno, string $tipo = 'confirmacion'): WhatsappMensaje
    {
        return WhatsappMensaje::create([
            'user_id' => $this->user->id,
            'turno_id' => $turno?->id,
            'numero' => '5493765252395',
            'mensaje' => 'texto',
            'tipo' => $tipo,
            'message_id' => 'wamid.' . uniqid(),
            'status' => 'failed',
        ]);
    }

    // ── Job ──────────────────────────────────────────────────────

    public function test_job_notifies_the_owner_once_with_title_url_and_tag(): void
    {
        Notification::fake();
        $this->user->updatePushSubscription('https://push.example/a', 'k', 't');
        $this->user->updatePushSubscription('https://push.example/b', 'k', 't');
        $m = $this->mensaje($this->turno(self::FECHA . ' 15:30:00', [$this->servicio('Esmaltado')]), 'recordatorio');

        (new EnviarPushFalloWhatsapp($m->id))->handle();

        Notification::assertSentToTimes($this->user, FalloMensajeWhatsapp::class, 1);
        Notification::assertSentTo($this->user, FalloMensajeWhatsapp::class, function (FalloMensajeWhatsapp $n) use ($m) {
            $p = $n->payload;

            return $p['title'] === 'No se pudo enviar el recordatorio'
                && $p['url'] === '/agenda/notificaciones/' . $m->id
                && $p['tag'] === 'fallo-whatsapp-' . $m->id
                && is_int($p['timestamp']);
        });
    }

    public function test_job_skips_accounts_without_subscriptions(): void
    {
        Notification::fake();
        $m = $this->mensaje($this->turno(self::FECHA . ' 15:30:00', [$this->servicio('Esmaltado')]));

        (new EnviarPushFalloWhatsapp($m->id))->handle();

        Notification::assertNothingSent();
    }

    public function test_job_with_a_missing_mensaje_is_a_no_op(): void
    {
        Notification::fake();

        (new EnviarPushFalloWhatsapp(999999))->handle();

        Notification::assertNothingSent();
    }

    public function test_job_still_notifies_when_the_turno_was_deleted(): void
    {
        Notification::fake();
        $this->user->updatePushSubscription('https://push.example/a', 'k', 't');
        $m = $this->mensaje(null);

        (new EnviarPushFalloWhatsapp($m->id))->handle();

        Notification::assertSentTo($this->user, FalloMensajeWhatsapp::class, fn (FalloMensajeWhatsapp $n) => $n->payload['body'] === 'Tocá para ver el detalle y reenviarlo a mano');
    }

    // ── Notification ─────────────────────────────────────────────

    public function test_notification_uses_a_six_hour_ttl_and_high_urgency(): void
    {
        $payload = ['title' => 't', 'body' => 'b', 'url' => '/u', 'tag' => 'x', 'timestamp' => 1];
        $n = new FalloMensajeWhatsapp($payload);

        $message = $n->toWebPush($this->user, $n);

        $this->assertSame(['TTL' => 21600, 'urgency' => 'high'], $message->getOptions());
        $this->assertSame($payload, $message->toArray());
    }

    // ── Payload ──────────────────────────────────────────────────

    public function test_titles_per_tipo(): void
    {
        $turno = $this->turno(self::FECHA . ' 15:30:00', [$this->servicio('Esmaltado')]);

        $this->assertSame('No se pudo enviar la confirmación', FalloWhatsappPayload::para($this->mensaje($turno, 'confirmacion'))['title']);
        $this->assertSame('No se pudo enviar el recordatorio', FalloWhatsappPayload::para($this->mensaje($turno, 'recordatorio'))['title']);
        $this->assertSame('No se pudo enviar el aviso de reprogramación', FalloWhatsappPayload::para($this->mensaje($turno, 'reprogramacion'))['title']);
        $this->assertSame('No se pudo enviar un mensaje de WhatsApp', FalloWhatsappPayload::para($this->mensaje($turno, 'cancelacion'))['title']);
    }

    public function test_payload_url_tag_and_shape(): void
    {
        $m = $this->mensaje($this->turno(self::FECHA . ' 15:30:00', [$this->servicio('Esmaltado')]));

        $p = FalloWhatsappPayload::para($m);

        $this->assertSame(['title', 'body', 'url', 'tag', 'timestamp'], array_keys($p));
        $this->assertSame('/agenda/notificaciones/' . $m->id, $p['url']);
        $this->assertSame('fallo-whatsapp-' . $m->id, $p['tag']);
    }

    public function test_payload_body_has_client_services_and_date(): void
    {
        $m = $this->mensaje($this->turno(self::FECHA . ' 15:30:00', [$this->servicio('Esmaltado'), $this->servicio('Semis')]));

        $this->assertSame("Camila R. · Esmaltado + 1 más\nJue 11 jun · 15:30", FalloWhatsappPayload::para($m)['body']);
    }

    public function test_payload_body_uses_the_raw_stored_wall_clock_time(): void
    {
        // The datetime cast round-trip shifts ~3h when app.timezone is not UTC;
        // the payload must read the raw stored value instead.
        $turno = $this->turno(self::FECHA . ' 09:05:00', [$this->servicio('Esmaltado')]);
        $m = $this->mensaje($turno);

        $p = FalloWhatsappPayload::para(WhatsappMensaje::with('turno.cliente', 'turno.servicios')->find($m->id));

        $this->assertStringContainsString('Jue 11 jun · 09:05', $p['body']);
    }

    public function test_payload_body_falls_back_when_the_turno_is_missing(): void
    {
        $p = FalloWhatsappPayload::para($this->mensaje(null));

        $this->assertSame('Tocá para ver el detalle y reenviarlo a mano', $p['body']);
    }

    public function test_payload_body_falls_back_when_the_client_is_missing(): void
    {
        $turno = $this->turno(self::FECHA . ' 15:30:00', [$this->servicio('Esmaltado')]);
        $turno->forceFill(['cliente_id' => null])->save();

        $p = FalloWhatsappPayload::para($this->mensaje($turno->fresh()));

        $this->assertSame('Tocá para ver el detalle y reenviarlo a mano', $p['body']);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Jobs\EnviarPushReservaOnline;
use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use App\Notifications\NuevaReservaOnline;
use App\Services\Push\ReservaOnlinePayload;
use App\Services\Reservas\ConfirmacionResultado;
use App\Services\Reservas\ConfirmarReservaService;
use App\Services\Reservas\HoldService;
use App\Services\Reservas\ReputacionService;
use Illuminate\Contracts\Bus\Dispatcher;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Mockery;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class ReservaOnlinePushTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    // 2099-06-11 is a Thursday.
    private const FECHA = '2099-06-11';

    private User $user;
    private Profesional $ana;
    private Profesional $laura;
    private Servicio $esmaltado;
    private Servicio $semis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->crearSalon();
        $this->ana = $this->crearProfesional($this->user, 'Ana');
        $this->laura = $this->crearProfesional($this->user, 'Laura');
        $this->esmaltado = $this->crearServicio($this->user, 'Esmaltado semipermanente', 60, true, $this->ana);
        $this->semis = $this->crearServicio($this->user, 'Semis', 45, true, $this->laura);
        foreach (['10:00', '10:30', '11:00', '11:30', '12:00', '15:30'] as $h) {
            $this->crearSlot($this->user, $this->ana, $h);
            $this->crearSlot($this->user, $this->laura, $h);
        }
    }

    private function ahora(): Carbon
    {
        return Carbon::parse('2099-06-01 09:00:00');
    }

    private function reservaSimple(): ReservaWeb
    {
        return ReservaWeb::create([
            'user_id' => $this->user->id,
            'profesional_id' => $this->ana->id,
            'public_token' => ReservaWeb::generarToken(),
            'servicio_ids' => [$this->esmaltado->id],
            'fecha' => self::FECHA,
            'slot_hora' => '10:00:00',
            'duracion_total_minutos' => 60,
            'estado' => 'pending_payment',
            'expira_en' => $this->ahora()->timestamp + 900,
            'nombre' => 'Camila',
            'apellido' => 'Rodriguez',
            'nombre_completo' => 'Camila Rodriguez',
            'telefono' => '+5493765252395',
        ]);
    }

    private function reservaGrupo(): ReservaWeb
    {
        $asignaciones = [
            ['servicio_ids' => [$this->esmaltado->id], 'profesional_id' => $this->ana->id],
            ['servicio_ids' => [$this->semis->id], 'profesional_id' => $this->laura->id],
        ];
        $r = app(HoldService::class)->retener(
            $this->user, $asignaciones, 'secuencia', self::FECHA, '10:00',
            (new ReputacionService())->hashDevice('dev-1'), 'key-1', $this->ahora(),
        )->reserva;
        $r->update([
            'nombre' => 'Camila', 'apellido' => 'Rodriguez', 'nombre_completo' => 'Camila Rodriguez',
            'telefono' => '+5493765252395', 'estado' => 'pending_payment',
        ]);

        return $r->fresh();
    }

    private function confirmar(ReservaWeb $r): ConfirmacionResultado
    {
        return app(ConfirmarReservaService::class)->confirmar($r, $this->ahora());
    }

    private function turno(Profesional $prof, string $fechaHora, array $servicios, ?Cliente $cliente = null, ?int $grupoId = null, ?int $reservaId = null): Turno
    {
        $cliente ??= Cliente::firstOrCreate(
            ['user_id' => $this->user->id, 'nombre' => 'Camila', 'apellido' => 'Rodriguez'],
            ['telefono' => '+5493765252395'],
        );
        $turno = Turno::create([
            'user_id' => $this->user->id, 'profesional_id' => $prof->id, 'cliente_id' => $cliente->id,
            'reserva_web_id' => $reservaId, 'grupo_id' => $grupoId,
            'fecha_hora' => $fechaHora, 'duracion_total_minutos' => 60, 'estado' => 'confirmado', 'origen' => 'web',
        ]);
        $turno->servicios()->attach(array_map(fn (Servicio $s) => $s->id, $servicios));

        return $turno;
    }

    // ── Trigger ──────────────────────────────────────────────────

    public function test_confirming_an_online_booking_dispatches_one_push_job(): void
    {
        Queue::fake();

        $res = $this->confirmar($this->reservaSimple());

        $this->assertSame(ConfirmacionResultado::CONFIRMED, $res->resultado);
        Queue::assertPushed(EnviarPushReservaOnline::class, 1);
        Queue::assertPushed(EnviarPushReservaOnline::class, fn ($job) => $job->turnoId === $res->turno->id);
    }

    public function test_confirming_an_already_confirmed_booking_does_not_push_again(): void
    {
        Queue::fake();
        $r = $this->reservaSimple();

        $this->confirmar($r);
        $this->confirmar($r->fresh());

        Queue::assertPushed(EnviarPushReservaOnline::class, 1);
    }

    public function test_a_multi_turno_grupo_dispatches_one_push_job_only(): void
    {
        Queue::fake();

        $res = $this->confirmar($this->reservaGrupo());

        $this->assertSame(ConfirmacionResultado::CONFIRMED, $res->resultado);
        $this->assertSame(2, Turno::count());
        Queue::assertPushed(EnviarPushReservaOnline::class, 1);
    }

    public function test_a_booking_that_needs_refund_does_not_push(): void
    {
        Queue::fake();
        $r = $this->reservaSimple();
        $r->update(['nombre' => null]);

        $res = $this->confirmar($r->fresh());

        $this->assertSame(ConfirmacionResultado::NEEDS_REFUND, $res->resultado);
        Queue::assertNotPushed(EnviarPushReservaOnline::class);
    }

    // ── Job ──────────────────────────────────────────────────────

    public function test_job_notifies_the_owner_account_once_with_the_payload(): void
    {
        Notification::fake();
        $this->user->updatePushSubscription('https://push.example/a', 'k', 't');
        $this->user->updatePushSubscription('https://push.example/b', 'k', 't');
        $t = $this->turno($this->ana, self::FECHA . ' 15:30:00', [$this->esmaltado]);

        (new EnviarPushReservaOnline($t->id))->handle();

        Notification::assertSentToTimes($this->user, NuevaReservaOnline::class, 1);
        Notification::assertSentTo($this->user, NuevaReservaOnline::class, function (NuevaReservaOnline $n) use ($t) {
            $p = $n->payload;

            return $p['title'] === 'Nueva reserva online'
                && $p['url'] === '/agenda?fecha=' . self::FECHA
                && $p['tag'] === 'reserva-online-turno-' . $t->id
                && is_int($p['timestamp']);
        });
    }

    public function test_job_skips_accounts_without_subscriptions(): void
    {
        Notification::fake();
        $t = $this->turno($this->ana, self::FECHA . ' 15:30:00', [$this->esmaltado]);

        (new EnviarPushReservaOnline($t->id))->handle();

        Notification::assertNothingSent();
    }

    public function test_job_with_a_missing_turno_is_a_no_op(): void
    {
        Notification::fake();

        (new EnviarPushReservaOnline(999999))->handle();

        Notification::assertNothingSent();
    }

    public function test_job_triggered_from_any_turno_of_a_grupo_uses_the_tag_of_the_reservation(): void
    {
        $r = $this->reservaGrupo();
        $res = $this->confirmar($r);

        $payload = ReservaOnlinePayload::para($res->turno);

        $this->assertSame('reserva-online-' . $r->id, $payload['tag']);
    }

    // ── Payload ──────────────────────────────────────────────────

    public function test_payload_for_a_single_service(): void
    {
        $t = $this->turno($this->ana, self::FECHA . ' 15:30:00', [$this->esmaltado]);

        $p = ReservaOnlinePayload::para($t);

        $this->assertSame('Nueva reserva online', $p['title']);
        $this->assertSame("Camila R. · Esmaltado semipermanente\nJue 11 jun · 15:30 con Ana", $p['body']);
        $this->assertSame('/agenda?fecha=' . self::FECHA, $p['url']);
        $this->assertSame(['title', 'body', 'url', 'tag', 'timestamp'], array_keys($p));
    }

    public function test_payload_for_several_services_in_one_turno(): void
    {
        $t = $this->turno($this->ana, self::FECHA . ' 09:05:00', [$this->esmaltado, $this->semis]);

        $p = ReservaOnlinePayload::para($t);

        $this->assertSame("Camila R. · Esmaltado semipermanente + 1 más\nJue 11 jun · 09:05 con Ana", $p['body']);
    }

    public function test_payload_for_a_grupo_uses_the_earliest_time_and_counts_extra_professionals_and_services(): void
    {
        $grupo = \App\Models\TurnoGrupo::create(['modo' => 'secuencia']);
        $this->turno($this->laura, self::FECHA . ' 11:00:00', [$this->semis], null, $grupo->id);
        $lider = $this->turno($this->ana, self::FECHA . ' 10:00:00', [$this->esmaltado], null, $grupo->id);

        // Built from the LATER turno on purpose: the result must not depend on which turno triggers it.
        $p = ReservaOnlinePayload::para(Turno::where('profesional_id', $this->laura->id)->first());

        $this->assertSame("Camila R. · Esmaltado semipermanente + 1 más\nJue 11 jun · 10:00 con Ana y 1 más", $p['body']);
        $this->assertSame(self::FECHA, substr($p['url'], -10));
        $this->assertNotNull($lider);
    }

    public function test_payload_client_without_last_name_shows_the_first_name_only(): void
    {
        $cliente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Camila', 'telefono' => '+5493765252390']);
        $t = $this->turno($this->ana, self::FECHA . ' 15:30:00', [$this->esmaltado], $cliente);

        $this->assertStringStartsWith('Camila · Esmaltado', ReservaOnlinePayload::para($t)['body']);
    }

    public function test_payload_time_is_the_stored_wall_clock_regardless_of_app_timezone_shift(): void
    {
        // The datetime cast round-trip shifts ~3h when app.timezone is not UTC;
        // the payload must read the raw stored value instead.
        $t = $this->turno($this->ana, self::FECHA . ' 15:30:00', [$this->esmaltado]);

        $p = ReservaOnlinePayload::para(Turno::find($t->id));

        $this->assertStringContainsString('15:30', $p['body']);
    }

    // ── Delivery / failure handling (web-push sender is mocked) ──

    /** Swap the real sender for a mock whose flush() yields the given reports. */
    private function fakeSender(callable $flush): void
    {
        $webPush = Mockery::mock(WebPush::class);
        $webPush->shouldReceive('queueNotification')->andReturnNull();
        $webPush->shouldReceive('flush')->andReturnUsing($flush);

        $this->app->bind(
            WebPushChannel::class,
            fn ($app) => new WebPushChannel($webPush, $app->make(ReportHandler::class)),
        );
    }

    private function report(string $endpoint, int $status, bool $success): MessageSentReport
    {
        return new MessageSentReport(new Request('POST', $endpoint), new Response($status), $success, $success ? 'OK' : 'Gone');
    }

    public function test_a_gone_subscription_is_deleted_and_a_live_one_is_kept(): void
    {
        $this->user->updatePushSubscription('https://push.example/dead', 'k', 't');
        $this->user->updatePushSubscription('https://push.example/live', 'k', 't');
        $this->fakeSender(function () {
            yield $this->report('https://push.example/dead', 410, false);
            yield $this->report('https://push.example/live', 201, true);
        });
        $t = $this->turno($this->ana, self::FECHA . ' 15:30:00', [$this->esmaltado]);

        (new EnviarPushReservaOnline($t->id))->handle();

        $this->assertNull(PushSubscription::findByEndpoint('https://push.example/dead'));
        $this->assertNotNull(PushSubscription::findByEndpoint('https://push.example/live'));
    }

    public function test_a_404_subscription_is_deleted_too(): void
    {
        $this->user->updatePushSubscription('https://push.example/dead', 'k', 't');
        $this->fakeSender(function () {
            yield $this->report('https://push.example/dead', 404, false);
        });
        $t = $this->turno($this->ana, self::FECHA . ' 15:30:00', [$this->esmaltado]);

        (new EnviarPushReservaOnline($t->id))->handle();

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_a_transient_failure_keeps_the_subscription(): void
    {
        $this->user->updatePushSubscription('https://push.example/flaky', 'k', 't');
        $this->fakeSender(function () {
            yield $this->report('https://push.example/flaky', 503, false);
        });
        $t = $this->turno($this->ana, self::FECHA . ' 15:30:00', [$this->esmaltado]);

        (new EnviarPushReservaOnline($t->id))->handle();

        $this->assertNotNull(PushSubscription::findByEndpoint('https://push.example/flaky'));
    }

    public function test_a_sender_that_throws_never_breaks_the_job(): void
    {
        $this->user->updatePushSubscription('https://push.example/a', 'k', 't');
        $this->fakeSender(fn () => throw new \RuntimeException('push service down'));
        $t = $this->turno($this->ana, self::FECHA . ' 15:30:00', [$this->esmaltado]);

        (new EnviarPushReservaOnline($t->id))->handle();

        $this->assertTrue(true); // reaching here without an exception is the assertion
    }

    public function test_a_failing_push_does_not_break_or_roll_back_the_booking(): void
    {
        // Only the WhatsApp job is faked; the push job runs for real (sync queue) against a throwing sender.
        Queue::fake([EnviarMensajeConfirmacion::class]);
        $this->user->updatePushSubscription('https://push.example/a', 'k', 't');
        $this->fakeSender(fn () => throw new \RuntimeException('push service down'));
        $r = $this->reservaSimple();

        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::CONFIRMED, $res->resultado);
        $this->assertSame('confirmed', $r->fresh()->estado);
        $this->assertSame(1, Turno::where('reserva_web_id', $r->id)->count());
    }

    public function test_a_failure_while_dispatching_the_job_does_not_break_the_booking(): void
    {
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->andReturnUsing(function ($job) {
                if ($job instanceof EnviarPushReservaOnline) {
                    throw new \RuntimeException('queue down');
                }

                return 0;
            });
        });
        $r = $this->reservaSimple();

        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::CONFIRMED, $res->resultado);
        $this->assertSame('confirmed', $r->fresh()->estado);
    }
}

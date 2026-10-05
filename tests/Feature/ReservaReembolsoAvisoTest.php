<?php

namespace Tests\Feature;

use App\Jobs\EnviarPushReembolsoReserva;
use App\Models\PagoSena;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\User;
use App\Notifications\ReservaRequiereReembolso;
use App\Services\Push\ReembolsoPayload;
use App\Services\Reservas\ConfirmacionResultado;
use App\Services\Reservas\ConfirmarReservaService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

/**
 * requiere_reembolso se escribia pero nadie lo leia: la duena no se enteraba de
 * que una clienta pago sin conseguir turno. Ahora se le avisa por Web Push y la
 * campanita lo lista.
 */
class ReservaReembolsoAvisoTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    private const FECHA = '2099-06-11'; // jueves

    private User $user;
    private Profesional $ana;
    private Servicio $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->crearSalon();
        $this->ana = $this->crearProfesional($this->user, 'Ana');
        $this->servicio = $this->crearServicio($this->user, 'Esmaltado', 60, true, $this->ana);
        $this->crearSlot($this->user, $this->ana, '10:00');
    }

    private function reservaSinDatos(string $hora = '10:00:00'): ReservaWeb
    {
        return ReservaWeb::create([
            'user_id' => $this->user->id,
            'profesional_id' => $this->ana->id,
            'public_token' => ReservaWeb::generarToken(),
            'servicio_ids' => [$this->servicio->id],
            'fecha' => self::FECHA,
            'slot_hora' => $hora,
            'duracion_total_minutos' => 60,
            'estado' => 'pending_payment',
            'expira_en' => Carbon::parse('2099-06-01 09:00:00')->timestamp + 900,
            'nombre' => null, // fuerza needs_refund (datos_incompletos)
            'apellido' => 'Rodriguez',
            'telefono' => '+5493765252395',
        ]);
    }

    private function confirmar(ReservaWeb $r): ConfirmacionResultado
    {
        return app(ConfirmarReservaService::class)->confirmar($r, Carbon::parse('2099-06-01 09:00:00'));
    }

    public function test_needs_refund_despacha_un_aviso_a_la_duena(): void
    {
        Queue::fake();
        $r = $this->reservaSinDatos();

        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::NEEDS_REFUND, $res->resultado);
        Queue::assertPushed(EnviarPushReembolsoReserva::class, 1);
        Queue::assertPushed(EnviarPushReembolsoReserva::class, fn ($j) => $j->reservaId === $r->id);
    }

    public function test_si_no_se_puede_encolar_el_aviso_la_confirmacion_no_se_rompe(): void
    {
        $this->mock(Dispatcher::class, function ($m) {
            $m->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue down'));
        });
        $r = $this->reservaSinDatos();

        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::NEEDS_REFUND, $res->resultado);
        $this->assertTrue((bool) $r->fresh()->requiere_reembolso);
    }

    public function test_el_job_notifica_a_la_cuenta_con_el_payload(): void
    {
        Notification::fake();
        $this->user->updatePushSubscription('https://push.example/a', 'k', 't');
        $r = $this->reservaSinDatos();
        $r->update(['nombre' => 'Camila']);

        (new EnviarPushReembolsoReserva($r->id))->handle();

        Notification::assertSentToTimes($this->user, ReservaRequiereReembolso::class, 1);
        Notification::assertSentTo($this->user, ReservaRequiereReembolso::class, function ($n) use ($r) {
            return $n->payload['title'] === 'Seña cobrada sin turno'
                && $n->payload['tag'] === 'reembolso-reserva-' . $r->id
                && str_contains($n->payload['body'], 'Camila R.')
                && str_contains($n->payload['body'], 'Mercado Pago')
                && ! str_contains($n->payload['body'], '5493765252395');
        });
    }

    public function test_el_job_sin_suscripciones_o_con_reserva_inexistente_no_hace_nada(): void
    {
        Notification::fake();
        $r = $this->reservaSinDatos();

        (new EnviarPushReembolsoReserva($r->id))->handle();
        (new EnviarPushReembolsoReserva(999999))->handle();

        Notification::assertNothingSent();
    }

    public function test_el_payload_no_incluye_el_telefono_y_apunta_a_la_fecha(): void
    {
        $r = $this->reservaSinDatos();
        $r->update(['nombre' => 'Camila']);

        $p = ReembolsoPayload::para($r->fresh());

        $this->assertSame('/agenda?fecha=' . self::FECHA, $p['url']);
        $this->assertStringContainsString('Jue 11 jun · 10:00', $p['body']);
    }

    public function test_la_campanita_lista_los_reembolsos_pendientes_solo_del_usuario(): void
    {
        $r = $this->reservaSinDatos();
        $r->update(['nombre' => 'Camila', 'requiere_reembolso' => true]);
        PagoSena::create([
            'reserva_web_id' => $r->id, 'mp_preference_id' => 'P', 'init_point' => 'x',
            'monto' => 5000, 'estado' => 'aprobado', 'mp_payment_id' => 'PAY-1',
        ]);
        // Otra cuenta con su propio reembolso: no debe verse.
        $otro = $this->crearSalon();
        ReservaWeb::create([
            'user_id' => $otro->id, 'public_token' => ReservaWeb::generarToken(), 'servicio_ids' => [1],
            'fecha' => self::FECHA, 'slot_hora' => '10:00:00', 'duracion_total_minutos' => 60,
            'estado' => 'expired', 'requiere_reembolso' => true, 'nombre' => 'Otra',
        ]);
        // Reserva normal: tampoco.
        $this->reservaSinDatos('11:00:00');

        $json = $this->actingAs($this->user, 'sanctum')->getJson('/api/turnos/notificaciones')
            ->assertOk()->json();

        $this->assertCount(1, $json['reembolsos_pendientes']);
        $item = $json['reembolsos_pendientes'][0];
        $this->assertSame($r->id, $item['reserva_id']);
        $this->assertSame('Camila', $item['cliente_nombre']);
        $this->assertSame(self::FECHA, $item['fecha']);
        $this->assertSame('10:00', $item['hora']);
        $this->assertEquals(5000, $item['monto']);
        $this->assertSame('PAY-1', $item['mp_payment_id']);
        $this->assertArrayNotHasKey('telefono', $item);
    }
}

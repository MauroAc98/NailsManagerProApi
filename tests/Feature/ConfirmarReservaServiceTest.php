<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use App\Services\Reservas\ConfirmarReservaService;
use App\Services\Reservas\ConfirmacionResultado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class ConfirmarReservaServiceTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    private const FECHA = '2099-06-11';

    private User $user;
    private Profesional $ana;
    private Servicio $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = $this->crearSalon();
        $this->ana = $this->crearProfesional($this->user, 'Ana');
        $this->servicio = $this->crearServicio($this->user, 'S', 60, true, $this->ana);
    }

    private function ahora(int $offset = 0): Carbon
    {
        return Carbon::parse('2099-06-01 09:00:00')->addSeconds($offset);
    }

    private function reserva(array $attrs = []): ReservaWeb
    {
        return ReservaWeb::create(array_merge([
            'user_id' => $this->user->id,
            'profesional_id' => $this->ana->id,
            'public_token' => ReservaWeb::generarToken(),
            'servicio_ids' => [$this->servicio->id],
            'fecha' => self::FECHA,
            'slot_hora' => '10:00:00',
            'duracion_total_minutos' => 60,
            'estado' => 'pending_payment',
            'expira_en' => $this->ahora()->timestamp + 900,
            'nombre' => 'Lucia',
            'apellido' => 'Gomez',
            'nombre_completo' => 'Lucia Gomez',
            'telefono' => '+5493765252395',
            'nota' => 'sin esmalte',
        ], $attrs));
    }

    private function confirmar(ReservaWeb $r, int $offset = 0): ConfirmacionResultado
    {
        return app(ConfirmarReservaService::class)->confirmar($r, $this->ahora($offset));
    }

    private function turnoDelSalon(string $hora, int $duracion = 30, ?Profesional $prof = null): Turno
    {
        $cliente = Cliente::firstOrCreate(['user_id' => $this->user->id, 'nombre' => 'Otra'], ['telefono' => '3760000000']);

        return Turno::create([
            'user_id' => $this->user->id, 'profesional_id' => ($prof ?? $this->ana)->id, 'cliente_id' => $cliente->id,
            'fecha_hora' => self::FECHA . " {$hora}:00", 'duracion_total_minutos' => $duracion,
            'estado' => 'confirmado', 'origen' => 'app',
        ]);
    }

    public function test_g11_un_hold_valido_crea_el_turno_web_con_cliente_nuevo_y_encola_la_confirmacion(): void
    {
        $r = $this->reserva();

        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::CONFIRMED, $res->resultado);
        $turno = $res->turno;
        $this->assertSame('web', $turno->origen);
        $this->assertSame($r->id, $turno->reserva_web_id);
        $this->assertSame($this->ana->id, $turno->profesional_id);
        $this->assertSame($this->user->id, $turno->user_id);
        $this->assertSame('confirmado', $turno->estado);
        $this->assertSame(60, $turno->duracion_total_minutos);
        $this->assertSame(self::FECHA . ' 10:00:00', $turno->fresh()->getRawOriginal('fecha_hora'));
        $this->assertSame('sin esmalte', $turno->notas);
        $this->assertSame([$this->servicio->id], $turno->servicios()->pluck('servicios.id')->all());

        $cliente = $turno->cliente;
        $this->assertSame('Lucia', $cliente->nombre);
        $this->assertSame('Gomez', $cliente->apellido);
        $this->assertSame('+5493765252395', $cliente->telefono);

        $r->refresh();
        $this->assertSame('confirmed', $r->estado);
        $this->assertSame($this->ahora()->timestamp, $r->confirmada_en);
        $this->assertFalse($r->requiere_reembolso);
        Queue::assertPushed(EnviarMensajeConfirmacion::class, 1);
    }

    public function test_reusa_la_clienta_existente_por_los_ultimos_10_digitos_del_telefono(): void
    {
        $existente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Lu', 'telefono' => '+543765252395']); // sin el 9
        $r = $this->reserva(['telefono' => '+5493765252395']);

        $res = $this->confirmar($r);

        $this->assertSame($existente->id, $res->turno->cliente_id);
        $this->assertSame(1, Cliente::where('user_id', $this->user->id)->count());
    }

    public function test_no_reusa_una_clienta_de_otro_salon(): void
    {
        $ajeno = $this->crearSalon();
        Cliente::create(['user_id' => $ajeno->id, 'nombre' => 'Otra', 'telefono' => '+5493765252395']);

        $res = $this->confirmar($this->reserva());

        $this->assertSame($this->user->id, $res->turno->cliente->user_id);
    }

    public function test_segunda_confirmacion_es_idempotente_sin_segundo_turno_ni_job(): void
    {
        $r = $this->reserva();
        $primero = $this->confirmar($r);

        $segundo = $this->confirmar($r->fresh(), 5);

        $this->assertSame(ConfirmacionResultado::ALREADY_CONFIRMED, $segundo->resultado);
        $this->assertSame($primero->turno->id, $segundo->turno->id);
        $this->assertSame(1, Turno::count());
        Queue::assertPushed(EnviarMensajeConfirmacion::class, 1);
    }

    public function test_g13_la_duena_agendo_sobre_el_hold_vivo_es_needs_refund_slot_conflict(): void
    {
        $r = $this->reserva();
        $this->turnoDelSalon('10:30', 30);

        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::NEEDS_REFUND, $res->resultado);
        $this->assertSame('slot_conflict', $res->motivo);
        $this->assertNull($res->turno);
        $this->assertSame(1, Turno::count());
        $r->refresh();
        $this->assertTrue($r->requiere_reembolso);
        $this->assertSame('expired', $r->estado);
        Queue::assertNothingPushed();
    }

    public function test_g12_pago_tardio_con_el_horario_libre_confirma(): void
    {
        $r = $this->reserva(['estado' => 'expired', 'expira_en' => $this->ahora()->timestamp - 100, 'motivo_cierre' => 'vencido']);

        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::CONFIRMED, $res->resultado);
        $this->assertSame('confirmed', $r->fresh()->estado);
        $this->assertFalse($r->fresh()->requiere_reembolso);
        Queue::assertPushed(EnviarMensajeConfirmacion::class, 1);
    }

    public function test_g12_pago_tardio_confirma_aunque_ya_no_cumpla_la_anticipacion_minima(): void
    {
        config(['reservas.anticipacion_minutos' => 120]);
        $r = $this->reserva(['estado' => 'expired', 'expira_en' => $this->ahora()->timestamp - 100]);

        // "ahora" = 09:30 del mismo dia del turno (10:00): dentro de la anticipacion, pero ya pago.
        $res = app(ConfirmarReservaService::class)->confirmar($r, Carbon::parse(self::FECHA . ' 09:30:00'));

        $this->assertSame(ConfirmacionResultado::CONFIRMED, $res->resultado);
    }

    public function test_g12_pago_tardio_con_el_horario_tomado_por_un_turno_es_needs_refund(): void
    {
        $r = $this->reserva(['estado' => 'expired', 'expira_en' => $this->ahora()->timestamp - 100]);
        $this->turnoDelSalon('10:00', 30);

        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::NEEDS_REFUND, $res->resultado);
        $this->assertSame('slot_taken_after_expiry', $res->motivo);
        $this->assertTrue($r->fresh()->requiere_reembolso);
        $this->assertSame('expired', $r->fresh()->estado);
        $this->assertSame(1, Turno::count());
    }

    public function test_g12_pago_tardio_con_el_horario_tomado_por_otro_hold_vivo_es_needs_refund(): void
    {
        $r = $this->reserva(['estado' => 'expired', 'expira_en' => $this->ahora()->timestamp - 100]);
        $this->reserva(['estado' => 'held', 'slot_hora' => '10:30:00', 'public_token' => ReservaWeb::generarToken()]);

        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::NEEDS_REFUND, $res->resultado);
        $this->assertSame('slot_taken_after_expiry', $res->motivo);
    }

    public function test_un_hold_cancelado_por_reemplazo_tambien_se_trata_como_pago_tardio(): void
    {
        $r = $this->reserva(['estado' => 'cancelled', 'motivo_cierre' => 'reemplazada']);

        $this->assertSame(ConfirmacionResultado::CONFIRMED, $this->confirmar($r)->resultado);
    }

    public function test_datos_incompletos_es_needs_refund(): void
    {
        $r = $this->reserva(['estado' => 'held', 'nombre' => null, 'telefono' => null, 'nombre_completo' => null]);

        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::NEEDS_REFUND, $res->resultado);
        $this->assertSame('datos_incompletos', $res->motivo);
        $this->assertTrue($r->fresh()->requiere_reembolso);
        $this->assertSame(0, Turno::count());
        Queue::assertNothingPushed();
    }

    public function test_needs_refund_repetido_no_duplica_ni_cambia_el_resultado(): void
    {
        $r = $this->reserva();
        $this->turnoDelSalon('10:00', 30);

        $this->confirmar($r);
        $segundo = $this->confirmar($r->fresh(), 10);

        $this->assertSame(ConfirmacionResultado::NEEDS_REFUND, $segundo->resultado);
        $this->assertTrue($r->fresh()->requiere_reembolso);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\TurnoGrupo;
use App\Models\User;
use App\Services\Reservas\ConfirmacionResultado;
use App\Services\Reservas\ConfirmarReservaService;
use App\Services\Reservas\HoldService;
use App\Services\Reservas\ReputacionService;
use App\Services\Servicios\PromoComponentes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

/**
 * combo-multi-profesional (PR 3c): ConfirmarReservaService con una reserva
 * `tramos` (promo componentizada o grupos sueltos multi-profesional) crea UN
 * turno_grupos + un Turno por tramo, todos ligados. Sin re-precio (usa el
 * precio_sugerido ya prorrateado en el hold); re-valida agenda dentro de
 * SlotLock::conLocks (todas las profesionales del plan).
 */
class ConfirmarGrupoTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    private const FECHA = '2099-06-11';

    private User $user;
    private Profesional $ana;
    private Profesional $laura;
    private Servicio $softgel;
    private Servicio $semis;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = $this->crearSalon();
        $this->ana = $this->crearProfesional($this->user, 'Ana');
        $this->laura = $this->crearProfesional($this->user, 'Laura');
        $this->softgel = $this->crearServicio($this->user, 'Softgel', 60, true, $this->ana);
        $this->semis = $this->crearServicio($this->user, 'Semis', 45, true, $this->laura);
        foreach (['10:00', '10:30', '11:00', '11:30', '12:00'] as $h) {
            $this->crearSlot($this->user, $this->ana, $h);
            $this->crearSlot($this->user, $this->laura, $h);
        }
    }

    private function ahora(int $offset = 0): Carbon
    {
        return Carbon::parse('2099-06-01 09:00:00')->addSeconds($offset);
    }

    private function asignacion(?Profesional $p, Servicio $s): array
    {
        return ['servicio_ids' => [$s->id], 'profesional_id' => $p?->id];
    }

    /** Crea un hold multi-tramo REAL via HoldService y lo deja listo para pagar (nombre/telefono + pending_payment). */
    private function holdListoParaPagar(array $asignaciones, string $modo, string $hora = '10:00'): ReservaWeb
    {
        $resultado = app(HoldService::class)->retener(
            $this->user, $asignaciones, $modo, self::FECHA, $hora,
            (new ReputacionService())->hashDevice('dev-1'), 'key-1', $this->ahora(),
        );
        $r = $resultado->reserva;
        $r->update([
            'nombre' => 'Lucia', 'apellido' => 'Gomez', 'nombre_completo' => 'Lucia Gomez',
            'telefono' => '+5493765252395', 'estado' => 'pending_payment',
        ]);

        return $r->fresh();
    }

    private function confirmar(ReservaWeb $r, int $offset = 0): ConfirmacionResultado
    {
        return app(ConfirmarReservaService::class)->confirmar($r, $this->ahora($offset));
    }

    public function test_secuencial_crea_un_turno_grupo_y_un_turno_por_tramo(): void
    {
        $r = $this->holdListoParaPagar([$this->asignacion($this->ana, $this->softgel), $this->asignacion($this->laura, $this->semis)], 'secuencia');

        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::CONFIRMED, $res->resultado);
        $this->assertSame(1, TurnoGrupo::count());
        $this->assertSame(2, Turno::count());
        $this->assertSame(1, ReservaWeb::count());

        $grupo = TurnoGrupo::first();
        $this->assertSame($r->id, $grupo->reserva_web_id);
        $this->assertSame('secuencia', $grupo->modo);

        $lider = $res->turno;
        $this->assertSame($this->ana->id, $lider->profesional_id);
        $this->assertSame($grupo->id, $lider->grupo_id);
        $this->assertSame(self::FECHA . ' 10:00:00', $lider->fresh()->getRawOriginal('fecha_hora'));
        $this->assertSame(60, $lider->duracion_total_minutos);

        $segundo = Turno::where('profesional_id', $this->laura->id)->firstOrFail();
        $this->assertSame($grupo->id, $segundo->grupo_id);
        $this->assertSame(self::FECHA . ' 11:00:00', $segundo->fresh()->getRawOriginal('fecha_hora'));
        $this->assertSame(45, $segundo->duracion_total_minutos);
        $this->assertSame([$this->semis->id], $segundo->servicios()->pluck('servicios.id')->all());

        $r->refresh();
        $this->assertSame('confirmed', $r->estado);
        Queue::assertPushed(EnviarMensajeConfirmacion::class, 1);
    }

    public function test_paralelo_ambos_turnos_arrancan_a_la_misma_hora(): void
    {
        $this->user->update(['atiende_en_paralelo' => true]);
        $r = $this->holdListoParaPagar([$this->asignacion($this->ana, $this->softgel), $this->asignacion($this->laura, $this->semis)], 'paralelo');

        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::CONFIRMED, $res->resultado);
        $turnos = Turno::orderBy('id')->get();
        $this->assertCount(2, $turnos);
        foreach ($turnos as $turno) {
            $this->assertSame(self::FECHA . ' 10:00:00', $turno->getRawOriginal('fecha_hora'));
        }
        $this->assertSame(TurnoGrupo::first()->id, $turnos[0]->grupo_id);
        $this->assertSame(TurnoGrupo::first()->id, $turnos[1]->grupo_id);
    }

    public function test_promo_reparte_el_precio_sugerido_prorrateado_en_el_pivot(): void
    {
        $softgel = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Softgel promo', 'duracion_minutos' => 60, 'precio' => 6000, 'activo' => true]);
        $semis = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Semis promo', 'duracion_minutos' => 45, 'precio' => 4000, 'activo' => true]);
        $promo = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Combo', 'duracion_minutos' => 105, 'precio' => 10000, 'activo' => true, 'es_promo' => true]);
        $this->ana->servicios()->attach($softgel->id);
        $this->laura->servicios()->attach($semis->id);
        app(PromoComponentes::class)->reemplazar($promo, 'secuencia', [
            ['servicio_id' => $softgel->id, 'profesional_id' => $this->ana->id],
            ['servicio_id' => $semis->id, 'profesional_id' => $this->laura->id],
        ], null);

        $r = $this->holdListoParaPagar([$this->asignacion(null, $promo)], 'secuencia');
        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::CONFIRMED, $res->resultado);
        $lider = $res->turno;
        $this->assertSame(6000, $lider->servicios()->wherePivot('servicio_id', $softgel->id)->first()->pivot->precio_sugerido);
        $this->assertNull($lider->servicios()->first()->pivot->precio);

        $segundo = Turno::where('profesional_id', $this->laura->id)->firstOrFail();
        $this->assertSame(4000, $segundo->servicios()->wherePivot('servicio_id', $semis->id)->first()->pivot->precio_sugerido);
    }

    public function test_segunda_confirmacion_de_grupo_es_idempotente_sin_duplicar_turnos(): void
    {
        $r = $this->holdListoParaPagar([$this->asignacion($this->ana, $this->softgel), $this->asignacion($this->laura, $this->semis)], 'secuencia');
        $primero = $this->confirmar($r);

        $segundo = $this->confirmar($r->fresh(), 5);

        $this->assertSame(ConfirmacionResultado::ALREADY_CONFIRMED, $segundo->resultado);
        $this->assertSame($primero->turno->id, $segundo->turno->id);
        $this->assertSame(2, Turno::count());
        $this->assertSame(1, TurnoGrupo::count());
        Queue::assertPushed(EnviarMensajeConfirmacion::class, 1);
    }

    public function test_tramo_ya_no_alineado_a_un_slot_es_needs_refund_slot_desalineado(): void
    {
        $r = $this->holdListoParaPagar([$this->asignacion($this->ana, $this->softgel), $this->asignacion($this->laura, $this->semis)], 'secuencia');
        // Laura pierde el slot de las 11:00 (su tramo) despues del hold.
        SlotDisponible::where('profesional_id', $this->laura->id)->where('hora', '11:00')->update(['activo' => false]);

        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::NEEDS_REFUND, $res->resultado);
        $this->assertSame('slot_desalineado', $res->motivo);
        $this->assertNull($res->turno);
        $this->assertSame(0, Turno::count());
        $this->assertSame(0, TurnoGrupo::count());
        $r->refresh();
        $this->assertTrue($r->requiere_reembolso);
        Queue::assertNotPushed(EnviarMensajeConfirmacion::class);
        Queue::assertNotPushed(\App\Jobs\EnviarPushReservaOnline::class);
        // Solo sale el aviso de reembolso a la duena.
        Queue::assertPushed(\App\Jobs\EnviarPushReembolsoReserva::class, 1);
    }

    public function test_tramo_ocupado_por_otro_turno_antes_de_confirmar_es_needs_refund_slot_desalineado(): void
    {
        $r = $this->holdListoParaPagar([$this->asignacion($this->ana, $this->softgel), $this->asignacion($this->laura, $this->semis)], 'secuencia');
        // La duena agendo sobre el tramo de Laura (11:00-11:45) desde otro turno confirmado.
        $cliente = $this->user->clientes()->create(['nombre' => 'Otra', 'telefono' => '3760000000']);
        Turno::create([
            'user_id' => $this->user->id, 'profesional_id' => $this->laura->id, 'cliente_id' => $cliente->id,
            'fecha_hora' => self::FECHA . ' 11:00:00', 'duracion_total_minutos' => 30,
            'estado' => 'confirmado', 'origen' => 'app',
        ]);

        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::NEEDS_REFUND, $res->resultado);
        $this->assertSame('slot_desalineado', $res->motivo);
        $this->assertSame(1, Turno::count());
        $r->refresh();
        $this->assertTrue($r->requiere_reembolso);
    }

    public function test_datos_incompletos_en_grupo_es_needs_refund(): void
    {
        $r = $this->holdListoParaPagar([$this->asignacion($this->ana, $this->softgel), $this->asignacion($this->laura, $this->semis)], 'secuencia');
        $r->update(['nombre' => null, 'telefono' => null, 'nombre_completo' => null]);

        $res = $this->confirmar($r);

        $this->assertSame(ConfirmacionResultado::NEEDS_REFUND, $res->resultado);
        $this->assertSame('datos_incompletos', $res->motivo);
        $this->assertSame(0, Turno::count());
        Queue::assertNotPushed(EnviarMensajeConfirmacion::class);
        Queue::assertNotPushed(\App\Jobs\EnviarPushReservaOnline::class);
        // Solo sale el aviso de reembolso a la duena.
        Queue::assertPushed(\App\Jobs\EnviarPushReembolsoReserva::class, 1);
    }
}

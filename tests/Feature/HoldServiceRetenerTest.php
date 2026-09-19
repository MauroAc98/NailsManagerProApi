<?php

namespace Tests\Feature;

use App\Exceptions\ReservaPublicaException;
use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use App\Services\Reservas\DisponibilidadService;
use App\Services\Reservas\HoldService;
use App\Services\Reservas\ReputacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class HoldServiceRetenerTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    private const FECHA = '2099-06-11';

    private User $user;
    private Profesional $ana;
    private Servicio $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->crearSalon();
        $this->ana = $this->crearProfesional($this->user, 'Ana');
        $this->servicio = $this->crearServicio($this->user, 'S', 90, true, $this->ana);
        foreach (['09:00', '10:00', '11:00', '12:00'] as $h) {
            $this->crearSlot($this->user, $this->ana, $h);
        }
    }

    private function ahora(): Carbon
    {
        return Carbon::parse('2099-06-01 09:00:00');
    }

    private function svc(): HoldService
    {
        return app(HoldService::class);
    }

    private function retener(string $hora, ?int $profId, string $device = 'dev-1', string $key = 'key-1', ?array $servicioIds = null)
    {
        return $this->svc()->retener(
            $this->user,
            $servicioIds ?? [$this->servicio->id],
            $profId,
            self::FECHA,
            $hora,
            (new ReputacionService())->hashDevice($device),
            $key,
            $this->ahora(),
        );
    }

    private function codigo(callable $fn): string
    {
        try {
            $fn();
        } catch (ReservaPublicaException $e) {
            return $e->codigo;
        }

        return 'no-exception';
    }

    private function turno(Profesional $prof, string $hora, int $duracion = 30): void
    {
        $cliente = Cliente::firstOrCreate(['user_id' => $this->user->id, 'nombre' => 'C'], ['telefono' => '3765252395']);
        Turno::create([
            'user_id' => $this->user->id, 'profesional_id' => $prof->id, 'cliente_id' => $cliente->id,
            'fecha_hora' => self::FECHA . " {$hora}:00", 'duracion_total_minutos' => $duracion,
            'estado' => 'confirmado', 'origen' => 'app',
        ]);
    }

    public function test_g1_crea_el_hold_con_expiracion_de_10_minutos_y_saca_el_horario_solo_a_esa_profesional(): void
    {
        $bea = $this->crearProfesional($this->user, 'Bea');
        $bea->servicios()->attach($this->servicio->id);
        $this->crearSlot($this->user, $bea, '10:00');

        $r = $this->retener('10:00', $this->ana->id);

        $this->assertFalse($r->replay);
        $h = $r->reserva->fresh();
        $this->assertSame('held', $h->estado);
        $this->assertSame($this->ana->id, $h->profesional_id);
        $this->assertSame(self::FECHA, $h->fecha);
        $this->assertSame('10:00:00', $h->slot_hora);
        $this->assertSame(90, $h->duracion_total_minutos);
        $this->assertSame($this->ahora()->timestamp + 600, $h->expira_en);
        $this->assertFalse($h->alta_ocupacion);
        $this->assertSame([$this->servicio->id], $h->servicio_ids);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $h->public_token);
        $this->assertSame((new ReputacionService())->hashDevice('dev-1'), $h->device_hash);
        $this->assertSame('key-1', $h->idempotency_key);

        $slots = (new DisponibilidadService())->calcular($this->user, self::FECHA, collect([$this->servicio]), null, $this->ahora(), 120);
        $por = collect($slots)->keyBy('hora');
        $this->assertSame([$bea->id], $por['10:00']['profesional_ids']);
    }

    public function test_alta_ocupacion_acorta_el_hold_a_5_minutos(): void
    {
        // 4 slots de Ana; 3 ocupados por turnos (75% >= 70%) y se retiene el 4to.
        foreach (['09:00', '10:00', '11:00'] as $h) {
            $this->turno($this->ana, $h, 30);
        }
        $servicio30 = $this->crearServicio($this->user, 'Corto', 30, true, $this->ana);

        $r = $this->retener('12:00', $this->ana->id, 'dev-1', 'key-1', [$servicio30->id]);

        $this->assertTrue($r->reserva->alta_ocupacion);
        $this->assertSame($this->ahora()->timestamp + 300, $r->reserva->expira_en);
    }

    public function test_g2_otro_dispositivo_no_puede_retener_un_horario_que_pisa_un_hold_vivo(): void
    {
        $this->retener('10:00', $this->ana->id, 'dev-1');

        $this->assertSame('slot_taken', $this->codigo(fn () => $this->retener('11:00', $this->ana->id, 'dev-2', 'k2'))); // [11:00,12:30) pisa [10:00,11:30)
        $this->assertSame('slot_taken', $this->codigo(fn () => $this->retener('09:00', $this->ana->id, 'dev-2', 'k3'))); // [09:00,10:30)
        $this->assertSame(1, ReservaWeb::count());
        // adyacente al fin: 12:00 arranca cuando el hold ya libero 11:30
        $this->assertFalse($this->retener('12:00', $this->ana->id, 'dev-2', 'k4')->replay);
    }

    public function test_g2_un_hold_vencido_por_tiempo_no_bloquea_y_se_ordena_como_expired(): void
    {
        $viejo = $this->retener('10:00', $this->ana->id, 'dev-1');
        $viejo->reserva->update(['expira_en' => $this->ahora()->timestamp - 1]);

        $nuevo = $this->retener('10:00', $this->ana->id, 'dev-2', 'k2');

        $this->assertNotSame($viejo->reserva->id, $nuevo->reserva->id);
        $this->assertSame('expired', $viejo->reserva->fresh()->estado);
        $this->assertSame('held', $nuevo->reserva->fresh()->estado);
    }

    public function test_g3_cualquiera_asigna_a_la_libre_y_en_empate_a_la_de_menor_id(): void
    {
        $bea = $this->crearProfesional($this->user, 'Bea');
        $bea->servicios()->attach($this->servicio->id);
        foreach (['09:00', '10:00', '11:00', '12:00'] as $h) {
            $this->crearSlot($this->user, $bea, $h);
        }

        $libres = $this->retener('10:00', null, 'dev-1', 'k1');
        $this->assertSame($this->ana->id, $libres->reserva->profesional_id);

        $this->turno($this->ana, '12:00');
        $ocupadaAna = $this->retener('12:00', null, 'dev-2', 'k2');
        $this->assertSame($bea->id, $ocupadaAna->reserva->profesional_id);

        // ninguna libre
        $this->assertSame('slot_taken', $this->codigo(fn () => $this->retener('12:00', null, 'dev-3', 'k3')));
    }

    public function test_g4_una_hora_que_no_es_un_slot_activo_no_crea_nada(): void
    {
        $this->crearSlot($this->user, $this->ana, '15:00', false);

        $this->assertSame('validation', $this->codigo(fn () => $this->retener('10:30', $this->ana->id)));
        $this->assertSame('validation', $this->codigo(fn () => $this->retener('15:00', $this->ana->id, 'dev-1', 'k2')));
        $this->assertSame('validation', $this->codigo(fn () => $this->retener('10:30', null, 'dev-1', 'k3')));
        $this->assertSame(0, ReservaWeb::count());
    }

    public function test_servicio_no_ofrecido_o_inexistente_es_validation_y_profesional_ajena_es_404(): void
    {
        $otro = $this->crearServicio($this->user, 'Otro', 30);
        $ajena = $this->crearProfesional($this->crearSalon(), 'Ajena');

        $this->assertSame('validation', $this->codigo(fn () => $this->retener('10:00', $this->ana->id, 'dev-1', 'k1', [$otro->id])));
        $this->assertSame('validation', $this->codigo(fn () => $this->retener('10:00', null, 'dev-1', 'k2', [9999])));
        $this->assertSame('validation', $this->codigo(fn () => $this->retener('10:00', null, 'dev-1', 'k3', [$otro->id]))); // nadie lo ofrece
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->retener('10:00', $ajena->id, 'dev-1', 'k4');
    }

    public function test_respeta_la_anticipacion_minima(): void
    {
        config(['reservas.anticipacion_minutos' => 120]);

        $r = $this->svc()->retener($this->user, [$this->servicio->id], $this->ana->id, self::FECHA, '10:00', 'h', 'k', Carbon::parse(self::FECHA . ' 07:30:00'));
        $this->assertSame('held', $r->reserva->estado); // 10:00 >= 07:30 + 2h

        $this->assertSame('slot_taken', $this->codigo(fn () => $this->svc()->retener($this->user, [$this->servicio->id], $this->ana->id, self::FECHA, '11:00', 'h2', 'k2', Carbon::parse(self::FECHA . ' 09:30:00'))));
    }

    public function test_g5_un_segundo_hold_del_mismo_dispositivo_libera_el_primero(): void
    {
        $primero = $this->retener('09:00', $this->ana->id, 'dev-1', 'k1');
        $segundo = $this->retener('12:00', $this->ana->id, 'dev-1', 'k2');

        $this->assertSame('cancelled', $primero->reserva->fresh()->estado);
        $this->assertSame('reemplazada', $primero->reserva->fresh()->motivo_cierre);
        $this->assertSame('held', $segundo->reserva->fresh()->estado);
    }

    public function test_g5_si_el_segundo_falla_el_primero_sigue_held(): void
    {
        $primero = $this->retener('09:00', $this->ana->id, 'dev-1', 'k1');
        $this->turno($this->ana, '12:00');

        $this->assertSame('slot_taken', $this->codigo(fn () => $this->retener('12:00', $this->ana->id, 'dev-1', 'k2')));

        $this->assertSame('held', $primero->reserva->fresh()->estado);
    }

    public function test_el_mismo_dispositivo_puede_re_retener_su_propio_horario_con_otra_key(): void
    {
        $primero = $this->retener('10:00', $this->ana->id, 'dev-1', 'k1');
        $segundo = $this->retener('10:00', $this->ana->id, 'dev-1', 'k2');

        $this->assertSame('cancelled', $primero->reserva->fresh()->estado);
        $this->assertSame('held', $segundo->reserva->fresh()->estado);
    }

    public function test_g6_mismo_dispositivo_y_key_es_replay_sin_segunda_fila(): void
    {
        $a = $this->retener('10:00', $this->ana->id, 'dev-1', 'k1');
        $b = $this->retener('10:00', $this->ana->id, 'dev-1', 'k1');

        $this->assertFalse($a->replay);
        $this->assertTrue($b->replay);
        $this->assertSame($a->reserva->id, $b->reserva->id);
        $this->assertSame(1, ReservaWeb::count());
    }

    public function test_g14_carrera_simulada_el_indice_unico_cae_como_slot_taken(): void
    {
        $svc = $this->svc();
        $svc->despuesDeVerificar = function (int $profesionalId) {
            // Un competidor inserta la misma llave entre el re-check y el insert.
            DB::table('reservas_web')->insert([
                'user_id' => $this->user->id, 'profesional_id' => $profesionalId, 'public_token' => 'competidor',
                'servicio_ids' => '[1]', 'fecha' => self::FECHA, 'slot_hora' => '10:00:00', 'duracion_total_minutos' => 90,
                'estado' => 'held', 'expira_en' => $this->ahora()->timestamp + 600, 'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $this->assertSame('slot_taken', $this->codigo(fn () => $svc->retener($this->user, [$this->servicio->id], $this->ana->id, self::FECHA, '10:00', 'h', 'k', $this->ahora())));
        // (El competidor del seam corre en la misma conexion, asi que su fila se revierte con el intento;
        // lo que se prueba es que el indice unico rechaza el insert y NO queda fila del solicitante.)
        $this->assertSame(0, ReservaWeb::where('idempotency_key', 'k')->count());
    }

    public function test_los_eventos_de_log_no_llevan_pii(): void
    {
        Log::spy();

        $this->retener('10:00', $this->ana->id);
        $this->codigo(fn () => $this->retener('11:00', $this->ana->id, 'dev-2', 'k2'));

        Log::shouldHaveReceived('info')->withArgs(function ($msg, $ctx = []) {
            return $msg === 'reserva.hold.created'
                && isset($ctx['user_id'], $ctx['profesional_id'], $ctx['reserva_id'], $ctx['device_prefix'])
                && strlen($ctx['device_prefix']) === 8
                && ! array_key_exists('telefono', $ctx) && ! array_key_exists('nombre', $ctx);
        })->once();
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx = []) => $msg === 'reserva.hold.slot_taken')->once();
    }
}

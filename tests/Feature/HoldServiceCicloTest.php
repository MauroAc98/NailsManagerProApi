<?php

namespace Tests\Feature;

use App\Exceptions\ReservaPublicaException;
use App\Models\Profesional;
use App\Models\ReservaReputacion;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\User;
use App\Services\Reservas\ExpirarHoldsService;
use App\Services\Reservas\HoldService;
use App\Services\Reservas\ReputacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class HoldServiceCicloTest extends TestCase
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
        $this->servicio = $this->crearServicio($this->user, 'S', 60, true, $this->ana);
        foreach (['09:00', '10:00', '11:00', '12:00'] as $h) {
            $this->crearSlot($this->user, $this->ana, $h);
        }
    }

    private function ahora(int $offset = 0): Carbon
    {
        return Carbon::parse('2099-06-01 09:00:00')->addSeconds($offset);
    }

    private function svc(): HoldService
    {
        return app(HoldService::class);
    }

    private function hold(string $hora = '10:00', string $device = 'dev-1', string $key = 'k1'): ReservaWeb
    {
        return $this->svc()->retener($this->user, [$this->servicio->id], $this->ana->id, self::FECHA, $hora, (new ReputacionService())->hashDevice($device), $key, $this->ahora())->reserva;
    }

    private function datos(ReservaWeb $r, string $tel = '+5491155551234', int $offset = 0)
    {
        return $this->svc()->guardarDatos($this->user, $r->public_token, 'Lu', 'Perez', $tel, 'nota', $this->ahora($offset))->reserva;
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

    // -- guardarDatos ---------------------------------------------

    public function test_guardar_datos_completa_el_hold_sin_moverle_la_expiracion(): void
    {
        $r = $this->hold();
        $exp = $r->expira_en;

        $r = $this->datos($r);

        $this->assertSame('Lu', $r->nombre);
        $this->assertSame('Perez', $r->apellido);
        $this->assertSame('Lu Perez', $r->nombre_completo);
        $this->assertSame('+5491155551234', $r->telefono);
        $this->assertSame('nota', $r->nota);
        $this->assertSame('held', $r->estado);
        $this->assertSame($exp, $r->expira_en);
    }

    public function test_guardar_datos_de_un_token_ajeno_o_inexistente_es_404(): void
    {
        $r = $this->hold();
        $otroSalon = $this->crearSalon();

        $this->assertSame('not_found', $this->codigo(fn () => $this->svc()->guardarDatos($otroSalon, $r->public_token, 'a', 'b', '+5491155551234', null, $this->ahora())));
        $this->assertSame('not_found', $this->codigo(fn () => $this->svc()->guardarDatos($this->user, 'x', 'a', 'b', '+5491155551234', null, $this->ahora())));
    }

    public function test_guardar_datos_de_un_hold_vencido_es_410_y_queda_expirado(): void
    {
        $r = $this->hold();

        $this->assertSame('hold_expired', $this->codigo(fn () => $this->datos($r, '+5491155551234', 601)));

        $this->assertSame('expired', $r->fresh()->estado);
    }

    public function test_guardar_datos_de_un_hold_liberado_es_410(): void
    {
        $r = $this->hold();
        $this->svc()->liberar($this->user, $r->public_token, $this->ahora());

        $this->assertSame('hold_expired', $this->codigo(fn () => $this->datos($r)));
    }

    public function test_g8_cooldown_de_telefono_libera_el_hold_y_da_429_con_retry_after(): void
    {
        $rep = new ReputacionService();
        $vencido = $this->hold('09:00', 'dev-1', 'k1');
        $this->datos($vencido, '+5491155551234');
        // vence sin pagar (a los 601s) y el job lo ordena
        app(ExpirarHoldsService::class)->expirarVencidos($this->ahora(601)->timestamp);
        $this->assertSame('expired', $vencido->fresh()->estado);

        $nuevo = $this->svc()->retener($this->user, [$this->servicio->id], $this->ana->id, self::FECHA, '11:00', $rep->hashDevice('dev-2'), 'k2', $this->ahora(700))->reserva;

        try {
            $this->svc()->guardarDatos($this->user, $nuevo->public_token, 'A', 'B', '+54 9 11 5555-1234', null, $this->ahora(700));
            $this->fail('debio dar phone_cooldown');
        } catch (ReservaPublicaException $e) {
            $this->assertSame('phone_cooldown', $e->codigo);
            $this->assertSame(429, $e->status);
            $this->assertSame(1800 - (700 - 601), $e->retryAfterSeconds);
        }
        $this->assertSame('cancelled', $nuevo->fresh()->estado);

        // pasados los 30 minutos: OK
        $otro = $this->svc()->retener($this->user, [$this->servicio->id], $this->ana->id, self::FECHA, '11:00', $rep->hashDevice('dev-2'), 'k3', $this->ahora(601 + 1801))->reserva;
        $this->assertSame('held', $this->svc()->guardarDatos($this->user, $otro->public_token, 'A', 'B', '+5491155551234', null, $this->ahora(601 + 1801))->reserva->estado);
    }

    public function test_g8_dos_vencidos_sin_pago_exigen_verificacion_solo_si_esta_habilitada(): void
    {
        $rep = new ReputacionService();
        $tel = $rep->hashTelefono('+5491155551234');
        $rep->registrarVencido($this->user->id, null, $tel, $this->ahora(-4000)->timestamp);
        $rep->registrarVencido($this->user->id, null, $tel, $this->ahora(-3000)->timestamp);

        // deshabilitada (default): pasa; el estado igual se registro
        $this->assertSame('held', $this->datos($this->hold())->estado);

        config(['reservas.verificacion_habilitada' => true]);
        $r2 = $this->hold('12:00', 'dev-2', 'k2');
        $this->assertSame('verification_required', $this->codigo(fn () => $this->datos($r2)));
        $this->assertSame('cancelled', $r2->fresh()->estado);

        // verificado: pasa
        $rep->marcarVerificado($this->user->id, 'phone', $tel, $this->ahora(10_000)->timestamp);
        $r3 = $this->hold('12:00', 'dev-3', 'k3');
        $this->assertSame('held', $this->datos($r3)->estado);
    }

    public function test_el_mismo_telefono_desde_otro_dispositivo_libera_el_hold_anterior(): void
    {
        $a = $this->hold('09:00', 'dev-1', 'k1');
        $this->datos($a);
        $b = $this->hold('11:00', 'dev-2', 'k2');

        $this->datos($b);

        $this->assertSame('cancelled', $a->fresh()->estado);
        $this->assertSame('telefono_duplicado', $a->fresh()->motivo_cierre);
        $this->assertSame('held', $b->fresh()->estado);
    }

    // -- iniciarPago ----------------------------------------------

    public function test_g7_pago_extiende_una_sola_vez_a_max_actual_ahora_mas_15_min(): void
    {
        $r = $this->hold();
        $this->datos($r);

        $p = $this->svc()->iniciarPago($this->user, $r->public_token, $this->ahora(100))->reserva;

        $this->assertSame('pending_payment', $p->estado);
        $this->assertTrue($p->pago_extendido);
        $this->assertSame($this->ahora(100)->timestamp + 900, $p->expira_en);

        $again = $this->svc()->iniciarPago($this->user, $r->public_token, $this->ahora(300))->reserva;
        $this->assertSame($p->expira_en, $again->expira_en);
        $this->assertSame('pending_payment', $again->estado);
    }

    public function test_pago_nunca_acorta_la_expiracion_actual(): void
    {
        $r = $this->hold();
        $this->datos($r);
        $r->update(['expira_en' => $this->ahora()->timestamp + 5000]);

        $p = $this->svc()->iniciarPago($this->user, $r->public_token, $this->ahora())->reserva;

        $this->assertSame($this->ahora()->timestamp + 5000, $p->expira_en);
    }

    public function test_pago_usa_la_ventana_corta_si_el_hold_nacio_en_alta_ocupacion(): void
    {
        $r = $this->hold();
        $this->datos($r);
        $r->update(['alta_ocupacion' => true]);

        $p = $this->svc()->iniciarPago($this->user, $r->public_token, $this->ahora(100))->reserva;

        $this->assertSame($this->ahora(100)->timestamp + 600, $p->expira_en);
    }

    public function test_pago_sin_datos_es_422_datos_required(): void
    {
        $r = $this->hold();

        $this->assertSame('datos_required', $this->codigo(fn () => $this->svc()->iniciarPago($this->user, $r->public_token, $this->ahora())));
        $this->assertSame('held', $r->fresh()->estado);
    }

    public function test_pago_de_un_hold_vencido_es_410(): void
    {
        $r = $this->hold();
        $this->datos($r);

        $this->assertSame('hold_expired', $this->codigo(fn () => $this->svc()->iniciarPago($this->user, $r->public_token, $this->ahora(601))));
    }

    // -- liberar --------------------------------------------------

    public function test_liberar_cancela_sin_penalizar_y_es_idempotente(): void
    {
        $r = $this->hold();
        $this->datos($r);

        $this->svc()->liberar($this->user, $r->public_token, $this->ahora(5));
        $this->svc()->liberar($this->user, $r->public_token, $this->ahora(6));

        $this->assertSame('cancelled', $r->fresh()->estado);
        $this->assertSame('liberada', $r->fresh()->motivo_cierre);
        $this->assertSame(0, ReservaReputacion::count(), 'liberar voluntariamente no penaliza');
    }

    public function test_liberar_un_hold_ya_vencido_no_falla_y_lo_deja_expirado(): void
    {
        $r = $this->hold();

        $this->svc()->liberar($this->user, $r->public_token, $this->ahora(700));

        $this->assertSame('expired', $r->fresh()->estado);
    }

    public function test_liberar_uno_confirmado_es_409_y_uno_ajeno_es_404(): void
    {
        $r = $this->hold();
        $r->update(['estado' => 'confirmed']);

        $this->assertSame('already_confirmed', $this->codigo(fn () => $this->svc()->liberar($this->user, $r->public_token, $this->ahora())));
        $this->assertSame('not_found', $this->codigo(fn () => $this->svc()->liberar($this->crearSalon(), $r->public_token, $this->ahora())));
    }

    // -- estado ---------------------------------------------------

    public function test_estado_devuelve_la_reserva_y_expira_de_forma_lazy(): void
    {
        $r = $this->hold();

        $this->assertSame('held', $this->svc()->estado($this->user, $r->public_token, $this->ahora(10))->estado);
        $this->assertSame('expired', $this->svc()->estado($this->user, $r->public_token, $this->ahora(601))->estado);
        $this->assertSame('expired', $r->fresh()->estado);
    }

    public function test_estado_de_un_token_desconocido_o_de_otro_salon_es_404(): void
    {
        $r = $this->hold();

        $this->assertSame('not_found', $this->codigo(fn () => $this->svc()->estado($this->user, str_repeat('a', 40), $this->ahora())));
        $this->assertSame('not_found', $this->codigo(fn () => $this->svc()->estado($this->crearSalon(), $r->public_token, $this->ahora())));
    }
}

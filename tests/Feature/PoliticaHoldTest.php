<?php

namespace Tests\Feature;

use App\Services\Reservas\PoliticaHold;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PoliticaHoldTest extends TestCase
{
    private function intervalo(string $desde, string $hasta): array
    {
        return [Carbon::parse("2099-06-11 {$desde}"), Carbon::parse("2099-06-11 {$hasta}")];
    }

    public function test_config_por_defecto(): void
    {
        $this->assertSame(10, config('reservas.hold_minutos'));
        $this->assertSame(5, config('reservas.hold_minutos_alta'));
        $this->assertSame(15, config('reservas.pago_minutos'));
        $this->assertSame(10, config('reservas.pago_minutos_alta'));
        $this->assertSame(0.7, config('reservas.ocupacion_alta_umbral'));
        $this->assertFalse(config('reservas.creacion_habilitada'));
        $this->assertFalse(config('reservas.verificacion_habilitada'));
        $this->assertFalse(config('reservas.challenge.habilitado'));
        $this->assertSame(30, config('reservas.cooldown_telefono_minutos'));
        $this->assertSame(2, config('reservas.verificacion_umbral'));
        $this->assertSame(24, config('reservas.verificacion_ventana_horas'));
    }

    public function test_duraciones_normal_y_alta(): void
    {
        $p = new PoliticaHold();

        $this->assertSame(10, $p->holdMinutos(false));
        $this->assertSame(5, $p->holdMinutos(true));
        $this->assertSame(15, $p->pagoMinutos(false));
        $this->assertSame(10, $p->pagoMinutos(true));
    }

    public function test_umbral_0_7_es_inclusivo(): void
    {
        $p = new PoliticaHold();
        $ahora = Carbon::parse('2099-06-11 00:00:00');
        $horas = ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00'];

        // 7 de 10 ocupados = 0.7 => alta.
        $siete = [$this->intervalo('08:00', '15:00')];
        $this->assertTrue($p->esAlta('2099-06-11', $horas, $siete, $ahora));

        // 6 de 10 => no alta.
        $seis = [$this->intervalo('08:00', '14:00')];
        $this->assertFalse($p->esAlta('2099-06-11', $horas, $seis, $ahora));
    }

    public function test_los_slots_pasados_no_cuentan_en_el_denominador(): void
    {
        $p = new PoliticaHold();
        $horas = ['08:00', '09:00', '10:00', '11:00'];
        // Ahora = 10:00: quedan 10:00 y 11:00; con 10:00 ocupado son 1/2 = 0.5 (no alta);
        // con ambos ocupados 2/2 => alta.
        $ahora = Carbon::parse('2099-06-11 10:00:00');

        $this->assertFalse($p->esAlta('2099-06-11', $horas, [$this->intervalo('10:00', '11:00')], $ahora));
        $this->assertTrue($p->esAlta('2099-06-11', $horas, [$this->intervalo('10:00', '12:00')], $ahora));
    }

    public function test_sin_slots_futuros_no_es_alta(): void
    {
        $p = new PoliticaHold();

        $this->assertFalse($p->esAlta('2099-06-11', ['08:00'], [$this->intervalo('08:00', '09:00')], Carbon::parse('2099-06-11 12:00:00')));
        $this->assertFalse($p->esAlta('2099-06-11', [], [], Carbon::parse('2099-06-11 12:00:00')));
    }

    public function test_intervalos_semiabiertos_el_fin_no_ocupa_el_slot_siguiente(): void
    {
        $p = new PoliticaHold();
        $ahora = Carbon::parse('2099-06-11 00:00:00');

        // Ocupado 08:00-09:00: el slot de las 09:00 NO esta ocupado.
        $this->assertFalse($p->esAlta('2099-06-11', ['08:00', '09:00'], [$this->intervalo('08:00', '09:00')], $ahora));
    }
}

<?php

namespace Tests\Feature;

use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\User;
use App\Services\Reservas\TotalReserva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Total de una reserva online = precio del turno que ve el cliente (base del
 * porcentaje de seña).
 */
class TotalReservaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_exempt' => true]);
    }

    private function servicio(?float $precio, bool $promo = false): Servicio
    {
        return Servicio::create([
            'user_id' => $this->user->id, 'nombre' => 'S'.uniqid(), 'duracion_minutos' => 30,
            'precio' => $precio, 'activo' => true, 'es_promo' => $promo,
        ]);
    }

    private function reserva(array $servicioIds, ?array $tramos = null): ReservaWeb
    {
        return ReservaWeb::create([
            'user_id' => $this->user->id, 'public_token' => ReservaWeb::generarToken(),
            'servicio_ids' => $servicioIds, 'fecha' => '2099-06-11', 'slot_hora' => '10:00:00',
            'duracion_total_minutos' => 60, 'estado' => 'held', 'expira_en' => now()->addMinutes(15)->timestamp,
            'tramos' => $tramos, 'tramos_modo' => $tramos ? 'secuencia' : null,
        ]);
    }

    private function tramo(array $servicioIds, ?int $precio, int $prof = 1, int $offset = 0): array
    {
        return ['profesional_id' => $prof, 'offset_minutos' => $offset, 'duracion_minutos' => 30,
            'servicio_ids' => $servicioIds, 'precio_sugerido' => $precio];
    }

    public function test_legacy_un_servicio(): void
    {
        $a = $this->servicio(12000);

        $this->assertSame(12000.0, (new TotalReserva())->de($this->reserva([$a->id])));
    }

    public function test_legacy_varios_servicios_suman(): void
    {
        $a = $this->servicio(12000);
        $b = $this->servicio(3500.50);

        $this->assertSame(15500.5, (new TotalReserva())->de($this->reserva([$a->id, $b->id])));
    }

    public function test_promo_con_tramos_usa_el_precio_prorrateado_y_no_los_precios_sueltos(): void
    {
        $c1 = $this->servicio(10000);
        $c2 = $this->servicio(10000);
        // servicio_ids de un plan trae los componentes (suman 20000 sueltos); la promo vale 15000.
        $reserva = $this->reserva([$c1->id, $c2->id], [
            $this->tramo([$c1->id], 7500, 1, 0),
            $this->tramo([$c2->id], 7500, 2, 0),
        ]);

        $this->assertSame(15000.0, (new TotalReserva())->de($reserva));
    }

    public function test_grupos_sueltos_con_tramos_sin_precio_suman_el_precio_de_sus_servicios(): void
    {
        $a = $this->servicio(8000);
        $b = $this->servicio(2000);
        $c = $this->servicio(1000);
        $reserva = $this->reserva([$a->id, $b->id, $c->id], [
            $this->tramo([$a->id], null, 1, 0),
            $this->tramo([$b->id, $c->id], null, 2, 30),
        ]);

        $this->assertSame(11000.0, (new TotalReserva())->de($reserva));
    }

    public function test_promo_mas_grupo_suelto_mezcla_precio_sugerido_y_precio_de_servicio(): void
    {
        $c1 = $this->servicio(10000);
        $c2 = $this->servicio(10000);
        $suelto = $this->servicio(4000);
        $reserva = $this->reserva([$c1->id, $c2->id, $suelto->id], [
            $this->tramo([$c1->id], 7500, 1, 0),
            $this->tramo([$c2->id], 7500, 2, 0),
            $this->tramo([$suelto->id], null, 3, 30),
        ]);

        $this->assertSame(19000.0, (new TotalReserva())->de($reserva));
    }

    public function test_tramo_fusionado_promo_mas_suelto_suma_el_precio_del_suelto(): void
    {
        $c1 = $this->servicio(10000);
        $c2 = $this->servicio(10000);
        $suelto = $this->servicio(4000);
        // TramosResolver fusiono el suelto en el tramo de la misma profesional: precio_sugerido
        // solo trae el prorrateo de la promo y servicios_sin_precio marca lo que no lo incluye.
        $fusionado = $this->tramo([$c2->id, $suelto->id], 7500, 2, 0) + ['servicios_sin_precio' => [$suelto->id]];
        $reserva = $this->reserva([$c1->id, $c2->id, $suelto->id], [$this->tramo([$c1->id], 7500, 1, 0), $fusionado]);

        $this->assertSame(19000.0, (new TotalReserva())->de($reserva));
    }

    public function test_tramo_fusionado_con_pieza_con_precio_y_pieza_de_servicio_con_precio_nulo(): void
    {
        $a = $this->servicio(null);
        $b = $this->servicio(3000);
        $fusionado = $this->tramo([$a->id, $b->id], 5000, 1, 0) + ['servicios_sin_precio' => [$a->id, $b->id]];

        $this->assertSame(8000.0, (new TotalReserva())->de($this->reserva([$a->id, $b->id], [$fusionado])));
    }

    public function test_precios_nulos_cuentan_como_cero(): void
    {
        $a = $this->servicio(null);
        $b = $this->servicio(5000);
        $legacy = $this->reserva([$a->id, $b->id]);
        $plan = $this->reserva([$a->id, $b->id], [
            $this->tramo([$a->id], null, 1, 0),
            $this->tramo([$b->id], null, 2, 0),
        ]);

        $this->assertSame(5000.0, (new TotalReserva())->de($legacy));
        $this->assertSame(5000.0, (new TotalReserva())->de($plan));
    }

    public function test_total_cero_cuando_ningun_servicio_tiene_precio_o_no_hay_servicios(): void
    {
        $a = $this->servicio(null);
        $b = $this->servicio(0);

        $this->assertSame(0.0, (new TotalReserva())->de($this->reserva([$a->id, $b->id])));
        $this->assertSame(0.0, (new TotalReserva())->de($this->reserva([])));
    }

    public function test_ignora_servicios_de_otro_salon(): void
    {
        $otro = User::factory()->create(['is_exempt' => true]);
        $ajeno = Servicio::create(['user_id' => $otro->id, 'nombre' => 'X', 'duracion_minutos' => 30, 'precio' => 9999, 'activo' => true]);

        $this->assertSame(0.0, (new TotalReserva())->de($this->reserva([$ajeno->id])));
    }
}

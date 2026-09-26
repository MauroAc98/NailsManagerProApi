<?php

namespace Tests\Unit;

use App\Services\Reservas\GrupoSuelto;
use App\Services\Reservas\PlanReserva;
use App\Services\Reservas\PromoInput;
use App\Services\Reservas\TramosResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * TramosResolver es puro: sin DB ni Eloquent. Fixture del spec: promo
 * "Softgel + Semis pies" = Softgel (Ana=7, 60 min, $13.000) + Semis pies
 * (Laura=3, 45 min, $9.000).
 */
class TramosResolverTest extends TestCase
{
    private const ANA = 7;
    private const LAURA = 3;
    private const MARTA = 5;

    private function resolver(): TramosResolver
    {
        return new TramosResolver();
    }

    private function promo(string $modo, ?int $precio = null): PromoInput
    {
        return new PromoInput($modo, [
            ['servicio_id' => 11, 'profesional_id' => self::ANA, 'duracion_minutos' => 60, 'precio' => 13000],
            ['servicio_id' => 12, 'profesional_id' => self::LAURA, 'duracion_minutos' => 45, 'precio' => 9000],
        ], $precio);
    }

    private function suelto(int $profesionalId, int $servicioId, int $duracion): GrupoSuelto
    {
        return new GrupoSuelto($profesionalId, [$servicioId], $duracion);
    }

    private function tramo(int $prof, int $offset, int $duracion, array $servicios, ?int $precio = null): array
    {
        return [
            'profesional_id' => $prof,
            'offset_minutos' => $offset,
            'duracion_minutos' => $duracion,
            'servicio_ids' => $servicios,
            'precio_sugerido' => $precio,
        ];
    }

    // ---- 1b.1 Rule L: legacy input never produces a plan ----

    public function test_sin_promo_y_sin_grupos_no_hay_plan(): void
    {
        $this->assertSame([], $this->resolver()->planes(null, [], true));
    }

    public function test_un_solo_grupo_sin_promo_es_legacy_aunque_el_estudio_atienda_en_paralelo(): void
    {
        $planes = $this->resolver()->planes(null, [$this->suelto(self::ANA, 11, 60)], true);

        $this->assertSame([], $planes);
    }

    public function test_promo_sin_componentes_es_legacy(): void
    {
        $planes = $this->resolver()->planes(new PromoInput(PlanReserva::SECUENCIA, [], 15000), [], true);

        $this->assertSame([], $planes);
    }

    // ---- 1b.2 promo timelines ----

    public function test_promo_secuencia_va_una_tras_otra_y_dura_la_suma(): void
    {
        $planes = $this->resolver()->planes($this->promo(PlanReserva::SECUENCIA), [], false);

        $this->assertCount(1, $planes);
        $this->assertSame(PlanReserva::SECUENCIA, $planes[0]->modo);
        $this->assertSame([
            $this->tramo(self::ANA, 0, 60, [11], 13000),
            $this->tramo(self::LAURA, 60, 45, [12], 9000),
        ], $planes[0]->tramos);
        $this->assertSame(105, $planes[0]->duracionTotalMinutos());
    }

    public function test_promo_paralelo_arranca_todo_en_cero_y_dura_el_maximo(): void
    {
        $planes = $this->resolver()->planes($this->promo(PlanReserva::PARALELO), [], false);

        $this->assertCount(1, $planes);
        $this->assertSame(PlanReserva::PARALELO, $planes[0]->modo);
        $this->assertSame([
            $this->tramo(self::ANA, 0, 60, [11], 13000),
            $this->tramo(self::LAURA, 0, 45, [12], 9000),
        ], $planes[0]->tramos);
        $this->assertSame(60, $planes[0]->duracionTotalMinutos());
    }

    // ---- 1b.3 proportional proration (half-up, remainder to the last tramo) ----

    public function test_prorrateo_del_precio_pisado_reparte_proporcional_con_el_resto_al_ultimo(): void
    {
        $tramos = $this->resolver()->planes($this->promo(PlanReserva::SECUENCIA, 18000), [], false)[0]->tramos;

        $this->assertSame([10636, 7364], array_column($tramos, 'precio_sugerido'));
    }

    public function test_prorrateo_sin_pisar_precio_devuelve_los_precios_standalone(): void
    {
        $tramos = $this->resolver()->planes($this->promo(PlanReserva::SECUENCIA, 22000), [], false)[0]->tramos;

        $this->assertSame([13000, 9000], array_column($tramos, 'precio_sugerido'));
    }

    public function test_prorrateo_de_tres_componentes_iguales_deja_el_resto_en_el_ultimo(): void
    {
        $componentes = array_map(fn (int $i) => [
            'servicio_id' => 20 + $i, 'profesional_id' => 30 + $i, 'duracion_minutos' => 30, 'precio' => 1000,
        ], [1, 2, 3]);

        $tramos = $this->resolver()->planes(new PromoInput(PlanReserva::PARALELO, $componentes, 10000), [], false)[0]->tramos;

        $this->assertSame([3333, 3333, 3334], array_column($tramos, 'precio_sugerido'));
    }

    public function test_prorrateo_redondea_half_up(): void
    {
        // 1 x 1/2 = 0.5 -> 1 (half-up); el resto queda en el ultimo.
        $componentes = [
            ['servicio_id' => 21, 'profesional_id' => 31, 'duracion_minutos' => 30, 'precio' => 1],
            ['servicio_id' => 22, 'profesional_id' => 32, 'duracion_minutos' => 30, 'precio' => 1],
        ];

        $tramos = $this->resolver()->planes(new PromoInput(PlanReserva::SECUENCIA, $componentes, 1), [], false)[0]->tramos;

        $this->assertSame([1, 0], array_column($tramos, 'precio_sugerido'));
    }

    public function test_prorrateo_con_precios_standalone_en_cero_deja_todo_en_el_ultimo(): void
    {
        $componentes = [
            ['servicio_id' => 21, 'profesional_id' => 31, 'duracion_minutos' => 30, 'precio' => 0],
            ['servicio_id' => 22, 'profesional_id' => 32, 'duracion_minutos' => 30, 'precio' => 0],
        ];

        $tramos = $this->resolver()->planes(new PromoInput(PlanReserva::SECUENCIA, $componentes, 500), [], false)[0]->tramos;

        $this->assertSame([0, 500], array_column($tramos, 'precio_sugerido'));
    }

    public function test_promo_paralelo_exige_profesionales_distintas(): void
    {
        $promo = new PromoInput(PlanReserva::PARALELO, [
            ['servicio_id' => 11, 'profesional_id' => self::ANA, 'duracion_minutos' => 60, 'precio' => 13000],
            ['servicio_id' => 12, 'profesional_id' => self::ANA, 'duracion_minutos' => 45, 'precio' => 9000],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->resolver()->planes($promo, [], true);
    }

    // ---- 1b.5 loose groups only: [paralelo, secuencia] or [secuencia] ----

    public function test_dos_grupos_sueltos_con_paralelo_habilitado_ofrecen_paralelo_y_luego_secuencia(): void
    {
        $grupos = [$this->suelto(self::ANA, 11, 60), $this->suelto(self::LAURA, 12, 45)];

        $planes = $this->resolver()->planes(null, $grupos, true);

        $this->assertSame([PlanReserva::PARALELO, PlanReserva::SECUENCIA], array_map(fn ($p) => $p->modo, $planes));
        $this->assertSame([
            $this->tramo(self::ANA, 0, 60, [11]),
            $this->tramo(self::LAURA, 0, 45, [12]),
        ], $planes[0]->tramos);
        $this->assertSame(60, $planes[0]->duracionTotalMinutos());
        $this->assertSame([
            $this->tramo(self::ANA, 0, 60, [11]),
            $this->tramo(self::LAURA, 60, 45, [12]),
        ], $planes[1]->tramos);
        $this->assertSame(105, $planes[1]->duracionTotalMinutos());
    }

    public function test_dos_grupos_sueltos_con_paralelo_deshabilitado_solo_ofrecen_secuencia(): void
    {
        $grupos = [$this->suelto(self::ANA, 11, 60), $this->suelto(self::LAURA, 12, 45)];

        $planes = $this->resolver()->planes(null, $grupos, false);

        $this->assertSame([PlanReserva::SECUENCIA], array_map(fn ($p) => $p->modo, $planes));
    }

    public function test_la_secuencia_de_grupos_sueltos_respeta_el_orden_de_seleccion(): void
    {
        $grupos = [$this->suelto(self::LAURA, 12, 45), $this->suelto(self::ANA, 11, 60)];

        $planes = $this->resolver()->planes(null, $grupos, false);

        $this->assertSame([
            $this->tramo(self::LAURA, 0, 45, [12]),
            $this->tramo(self::ANA, 45, 60, [11]),
        ], $planes[0]->tramos);
    }

    public function test_paralelo_de_grupos_sueltos_se_omite_si_se_repite_una_profesional(): void
    {
        $grupos = [
            $this->suelto(self::ANA, 11, 30),
            $this->suelto(self::LAURA, 12, 45),
            $this->suelto(self::ANA, 13, 30),
        ];

        $planes = $this->resolver()->planes(null, $grupos, true);

        $this->assertSame([PlanReserva::SECUENCIA], array_map(fn ($p) => $p->modo, $planes));
    }
}

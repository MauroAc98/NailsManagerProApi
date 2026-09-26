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
}

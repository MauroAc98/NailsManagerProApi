<?php

namespace Tests\Unit;

use App\Services\Reservas\AlineacionSlots;
use PHPUnit\Framework\TestCase;

/**
 * AlineacionSlots is pure: plain arrays in, plain arrays out (no DB, no
 * Eloquent). Tramos come from a PlanReserva (offsets in minutes from the start
 * S); slots are 'HH:MM' strings per professional. Fixture of the spec: promo
 * "Softgel + Semis pies" = Softgel (Ana=7, 60 min) + Semis pies (Laura=3, 45 min).
 */
class AlineacionSlotsTest extends TestCase
{
    private const ANA = 7;
    private const LAURA = 3;
    private const NOMBRES = [self::ANA => 'Ana', self::LAURA => 'Laura'];

    private function alineacion(): AlineacionSlots
    {
        return new AlineacionSlots();
    }

    private function tramo(int $prof, int $offset, int $duracion): array
    {
        return [
            'profesional_id' => $prof,
            'offset_minutos' => $offset,
            'duracion_minutos' => $duracion,
            'servicio_ids' => [1],
            'precio_sugerido' => null,
        ];
    }

    /** Softgel (Ana 60) then Semis pies (Laura 45), back to back. */
    private function secuencia(int $duracionAna = 60): array
    {
        return [$this->tramo(self::ANA, 0, $duracionAna), $this->tramo(self::LAURA, $duracionAna, 45)];
    }

    /** Both tramos start at S. */
    private function paralelo(): array
    {
        return [$this->tramo(self::ANA, 0, 60), $this->tramo(self::LAURA, 0, 45)];
    }

    // ---- primerDesalineado ----

    public function test_a_start_where_every_tramo_lands_on_its_own_slot_is_aligned(): void
    {
        $slots = [self::ANA => ['10:00', '10:30'], self::LAURA => ['11:00']];

        $this->assertNull($this->alineacion()->primerDesalineado($this->secuencia(), '10:00', $slots));
    }

    public function test_sequential_reports_the_professional_and_the_end_of_the_previous_tramo(): void
    {
        $slots = [self::ANA => ['10:00'], self::LAURA => ['10:30', '11:30']];

        $this->assertSame(
            ['profesional_id' => self::LAURA, 'hora_requerida' => '11:00'],
            $this->alineacion()->primerDesalineado($this->secuencia(), '10:00', $slots),
        );
    }

    public function test_parallel_requires_the_same_start_on_every_professional(): void
    {
        $slots = [self::ANA => ['10:00', '10:30'], self::LAURA => ['10:00', '11:00']];

        $this->assertNull($this->alineacion()->primerDesalineado($this->paralelo(), '10:00', $slots));
        $this->assertSame(
            ['profesional_id' => self::LAURA, 'hora_requerida' => '10:30'],
            $this->alineacion()->primerDesalineado($this->paralelo(), '10:30', $slots),
        );
    }

    public function test_a_90_minute_first_tramo_moves_the_required_time_of_the_second(): void
    {
        $alineacion = $this->alineacion();

        $this->assertNull($alineacion->primerDesalineado($this->secuencia(90), '10:00', [self::ANA => ['10:00'], self::LAURA => ['11:30']]));
        $this->assertSame(
            ['profesional_id' => self::LAURA, 'hora_requerida' => '11:30'],
            $alineacion->primerDesalineado($this->secuencia(90), '10:00', [self::ANA => ['10:00'], self::LAURA => ['11:00', '12:00']]),
        );
    }

    public function test_the_lead_tramo_must_also_start_on_a_slot_of_its_own_professional(): void
    {
        $slots = [self::ANA => ['10:00'], self::LAURA => ['11:30']];

        $this->assertSame(
            ['profesional_id' => self::ANA, 'hora_requerida' => '10:30'],
            $this->alineacion()->primerDesalineado($this->secuencia(), '10:30', $slots),
        );
    }

    public function test_a_professional_without_any_slot_is_never_aligned(): void
    {
        $this->assertSame(
            ['profesional_id' => self::LAURA, 'hora_requerida' => '11:00'],
            $this->alineacion()->primerDesalineado($this->secuencia(), '10:00', [self::ANA => ['10:00']]),
        );
    }

    public function test_a_tramo_that_crosses_midnight_is_never_aligned_even_with_a_slot_at_the_wrapped_time(): void
    {
        // Ana 23:00 + 90 min -> Laura would start 00:30 of the NEXT day.
        $slots = [self::ANA => ['23:00'], self::LAURA => ['00:30']];

        $this->assertSame(
            ['profesional_id' => self::LAURA, 'hora_requerida' => '00:30'],
            $this->alineacion()->primerDesalineado($this->secuencia(90), '23:00', $slots),
        );
    }

    // ---- analizarPromo ----

    public function test_sequential_misalignment_names_the_professional_the_missing_time_and_the_dropped_start(): void
    {
        $slots = [self::ANA => ['10:00', '10:30'], self::LAURA => ['10:30', '11:30']];

        $analisis = $this->alineacion()->analizarPromo($this->secuencia(), $slots, self::NOMBRES);

        $this->assertSame(['10:30'], $analisis['inicios_validos']);
        $this->assertSame([[
            'hora_inicio' => '10:00',
            'profesional_id' => self::LAURA,
            'profesional_nombre' => 'Laura',
            'hora_requerida' => '11:00',
            'mensaje' => 'Laura no tiene slot a las 11:00, esta promo no se ofrecerá a las 10:00',
        ]], $analisis['descartados']);
    }

    public function test_parallel_misalignment_message(): void
    {
        $slots = [self::ANA => ['10:00', '10:30'], self::LAURA => ['10:00', '11:00']];

        $analisis = $this->alineacion()->analizarPromo($this->paralelo(), $slots, self::NOMBRES);

        $this->assertSame(['10:00'], $analisis['inicios_validos']);
        $this->assertCount(1, $analisis['descartados']);
        $this->assertSame('Laura no tiene slot a las 10:30, esta promo no se ofrecerá a las 10:30', $analisis['descartados'][0]['mensaje']);
    }

    public function test_aligned_30_minute_slots_yield_no_warning(): void
    {
        $horas = ['10:00', '10:30', '11:00', '11:30', '12:00', '12:30'];
        // Laura covers every required time: each Ana start + 60 min.
        $lauraHoras = [...$horas, '13:00', '13:30'];

        $analisis = $this->alineacion()->analizarPromo($this->secuencia(), [self::ANA => $horas, self::LAURA => $lauraHoras], self::NOMBRES);

        $this->assertSame($horas, $analisis['inicios_validos']);
        $this->assertSame([], $analisis['descartados']);
    }

    public function test_hourly_professional_against_a_30_minute_lead_drops_only_the_half_hours(): void
    {
        $slots = [self::ANA => ['10:00', '10:30', '11:00'], self::LAURA => ['10:00', '11:00', '12:00']];

        $analisis = $this->alineacion()->analizarPromo($this->secuencia(), $slots, self::NOMBRES);

        $this->assertSame(['10:00', '11:00'], $analisis['inicios_validos']);
        $this->assertSame(['10:30'], array_column($analisis['descartados'], 'hora_inicio'));
        $this->assertSame(['11:30'], array_column($analisis['descartados'], 'hora_requerida'));
    }

    public function test_candidates_are_the_lead_professionals_slots_sorted_and_deduplicated(): void
    {
        $slots = [self::ANA => ['11:00', '10:00', '10:00'], self::LAURA => ['11:00', '12:00']];

        $analisis = $this->alineacion()->analizarPromo($this->secuencia(), $slots, self::NOMBRES);

        $this->assertSame(['10:00', '11:00'], array_merge($analisis['inicios_validos'], array_column($analisis['descartados'], 'hora_inicio')));
        $this->assertSame(['10:00', '11:00'], $analisis['inicios_validos']);
    }

    public function test_a_lead_professional_without_slots_has_nothing_to_analyze(): void
    {
        $analisis = $this->alineacion()->analizarPromo($this->secuencia(), [self::LAURA => ['11:00']], self::NOMBRES);

        $this->assertSame(['inicios_validos' => [], 'descartados' => []], $analisis);
    }

    public function test_a_single_merged_tramo_is_aligned_wherever_the_lead_has_a_slot(): void
    {
        $tramos = [$this->tramo(self::ANA, 0, 105)];

        $analisis = $this->alineacion()->analizarPromo($tramos, [self::ANA => ['10:00', '10:45']], self::NOMBRES);

        $this->assertSame(['10:00', '10:45'], $analisis['inicios_validos']);
        $this->assertSame([], $analisis['descartados']);
    }
}

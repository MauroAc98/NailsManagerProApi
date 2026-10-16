<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Concerns\DecoraListadoDeTurnos;
use App\Models\Cliente;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\TurnoGrupo;
use App\Services\Portal\TurnoPortalMapper;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The portal mapper is the single place where a Turno becomes the payload a
 * professional sees. These tests pin the exact key set at every level (an
 * extra key is a leak, so presence of unknown keys must fail) and the
 * parity of estado_visual with the owner's agenda. No database: models are
 * built in memory with their relations set by hand.
 */
class TurnoPortalMapperTest extends TestCase
{
    private const KEYS_TURNO = ['id', 'fecha_hora', 'duracion_total_minutos', 'estado_visual', 'notas', 'cliente', 'servicios', 'promo'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function turno(array $attrs = [], ?Cliente $cliente = null, array $servicios = [], ?TurnoGrupo $grupo = null): Turno
    {
        $turno = new Turno(array_merge([
            'fecha_hora' => '2026-10-17 10:30:00',
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
        ], $attrs));
        $turno->id = 77;
        $turno->setRelation('cliente', $cliente);
        $turno->setRelation('servicios', collect($servicios));
        $turno->setRelation('grupo', $grupo);

        return $turno;
    }

    private function cliente(string $nombre = 'Maria', ?string $apellido = 'Lopez'): Cliente
    {
        return new Cliente(['nombre' => $nombre, 'apellido' => $apellido, 'telefono' => '+5491155551234']);
    }

    private function servicio(int $id, string $nombre): Servicio
    {
        $servicio = new Servicio(['nombre' => $nombre, 'precio' => 99999]);
        $servicio->id = $id;
        // What a real eager load attaches: the pivot with the prices.
        $servicio->setRelation('pivot', (new Pivot)->forceFill(['precio' => 88888, 'precio_sugerido' => 77777]));

        return $servicio;
    }

    private function mapear(Turno $turno): array
    {
        return (new TurnoPortalMapper)->mapear($turno);
    }

    // ── exact key sets ───────────────────────────────────────────

    public function test_top_level_and_nested_keys_are_exactly_the_whitelist(): void
    {
        $promo = new Servicio(['nombre' => 'Manicura + Pedicura']);
        $grupo = new TurnoGrupo(['modo' => 'secuencia', 'precio_promo' => 18000]);
        $grupo->setRelation('promo', $promo);

        $dato = $this->mapear($this->turno(
            ['notas' => 'Llega con diseño', 'origen' => 'web', 'cliente_id' => 5, 'profesional_id' => 9, 'user_id' => 3, 'grupo_id' => 4, 'lote_id' => 'abc'],
            $this->cliente(),
            [$this->servicio(11, 'Capping'), $this->servicio(12, 'Esmaltado')],
            $grupo,
        ));

        $this->assertEqualsCanonicalizing(self::KEYS_TURNO, array_keys($dato));
        $this->assertEqualsCanonicalizing(['nombre', 'apellido'], array_keys($dato['cliente']));
        $this->assertCount(2, $dato['servicios']);
        foreach ($dato['servicios'] as $servicio) {
            $this->assertEqualsCanonicalizing(['id', 'nombre'], array_keys($servicio));
        }
        $this->assertEqualsCanonicalizing(['nombre'], array_keys($dato['promo']));
        $this->assertSame('Manicura + Pedicura', $dato['promo']['nombre']);
    }

    public function test_sensitive_values_present_on_the_models_never_reach_the_payload(): void
    {
        $dato = $this->mapear($this->turno(
            ['notas' => 'x', 'origen' => 'web', 'cliente_id' => 5, 'profesional_id' => 9, 'motivo_cancelacion' => 'MOTIVO-CANARIO'],
            $this->cliente(),
            [$this->servicio(11, 'Capping')],
        ));

        $json = json_encode($dato);
        foreach (['+5491155551234', '99999', '88888', '77777', 'MOTIVO-CANARIO', 'telefono', 'precio', 'pivot'] as $canario) {
            $this->assertStringNotContainsString($canario, $json);
        }
        $this->assertSame([['id' => 11, 'nombre' => 'Capping']], $dato['servicios']);
    }

    // ── scalar formatting ────────────────────────────────────────

    public function test_fecha_hora_is_the_stored_wall_clock_without_zone(): void
    {
        $this->assertSame('2026-10-17T10:30:00', $this->mapear($this->turno())['fecha_hora']);
        // Midnight is not shifted by the (non-UTC) application timezone.
        $this->assertSame('2026-10-18T00:00:00', $this->mapear($this->turno(['fecha_hora' => '2026-10-18 00:00:00']))['fecha_hora']);
        $this->assertSame('2026-10-17T23:59:59', $this->mapear($this->turno(['fecha_hora' => '2026-10-17 23:59:59']))['fecha_hora']);
    }

    public function test_id_and_duration_are_integers(): void
    {
        $dato = $this->mapear($this->turno(['duracion_total_minutos' => '45']));

        $this->assertSame(77, $dato['id']);
        $this->assertSame(45, $dato['duracion_total_minutos']);
    }

    // ── notas ────────────────────────────────────────────────────

    public static function notasVacias(): array
    {
        return ['null' => [null], 'empty string' => [''], 'only whitespace' => ["  \n\t "]];
    }

    #[DataProvider('notasVacias')]
    public function test_empty_notas_become_null(?string $notas): void
    {
        $this->assertNull($this->mapear($this->turno(['notas' => $notas]))['notas']);
    }

    public function test_notas_are_capped_at_500_characters_not_bytes(): void
    {
        $largas = str_repeat('ñ', 600);

        $dato = $this->mapear($this->turno(['notas' => $largas]));

        $this->assertSame(500, mb_strlen($dato['notas']));
        $this->assertSame(str_repeat('ñ', 500), $dato['notas']);
    }

    public function test_notas_of_exactly_500_characters_are_untouched_and_short_ones_verbatim(): void
    {
        $justas = str_repeat('a', 500);

        $this->assertSame($justas, $this->mapear($this->turno(['notas' => $justas]))['notas']);
        $this->assertSame('Trae su propio esmalte', $this->mapear($this->turno(['notas' => 'Trae su propio esmalte']))['notas']);
    }

    public function test_notas_with_markup_are_returned_verbatim_as_plain_text(): void
    {
        $malicioso = '<script>alert("x")</script> & <b>negrita</b>';

        $this->assertSame($malicioso, $this->mapear($this->turno(['notas' => $malicioso]))['notas']);
    }

    // ── cliente ──────────────────────────────────────────────────

    public function test_cliente_is_an_object_with_null_fields_when_the_turno_has_none(): void
    {
        $dato = $this->mapear($this->turno(['cliente_id' => null], null));

        $this->assertSame(['nombre' => null, 'apellido' => null], $dato['cliente']);
    }

    public function test_cliente_exposes_only_nombre_and_apellido(): void
    {
        $this->assertSame(
            ['nombre' => 'Maria', 'apellido' => 'Lopez'],
            $this->mapear($this->turno([], $this->cliente()))['cliente'],
        );
        $this->assertSame(
            ['nombre' => 'Sofia', 'apellido' => null],
            $this->mapear($this->turno([], $this->cliente('Sofia', null)))['cliente'],
        );
    }

    // ── promo ────────────────────────────────────────────────────

    public function test_promo_is_null_without_grupo_and_with_a_grupo_that_has_no_promo(): void
    {
        $this->assertNull($this->mapear($this->turno())['promo']);

        $suelto = new TurnoGrupo(['modo' => 'paralelo']);
        $suelto->setRelation('promo', null);
        $this->assertNull($this->mapear($this->turno([], null, [], $suelto))['promo']);
    }

    public function test_promo_of_a_two_professional_group_shows_only_her_step(): void
    {
        $promo = new Servicio(['nombre' => 'Manicura + Pedicura']);
        $grupo = new TurnoGrupo(['modo' => 'secuencia']);
        $grupo->setRelation('promo', $promo);
        // The sibling step (another professional) is reachable from the group
        // model if somebody loads it; the mapper must never walk there.
        $otra = $this->turno(['profesional_id' => 2, 'fecha_hora' => '2026-10-17 12:00:00'], $this->cliente(), [$this->servicio(99, 'Pedicura de Bea')]);
        $grupo->setRelation('turnos', collect([$otra]));

        $suyo = $this->turno(['duracion_total_minutos' => 30], $this->cliente(), [$this->servicio(11, 'Manicura de Ana')], $grupo);
        $dato = $this->mapear($suyo);

        $this->assertSame(['nombre' => 'Manicura + Pedicura'], $dato['promo']);
        $this->assertSame([['id' => 11, 'nombre' => 'Manicura de Ana']], $dato['servicios']);
        $this->assertSame(30, $dato['duracion_total_minutos']);
        $this->assertStringNotContainsString('Pedicura de Bea', json_encode($dato));
        $this->assertStringNotContainsString('12:00', json_encode($dato));
    }

    // ── estado_visual parity with the owner's agenda ─────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: string}>
     */
    public static function tablaEstadoVisual(): array
    {
        // [estado, fecha_hora, duracion, "now" of the test]
        return [
            'confirmado in progress' => ['confirmado', '2026-10-17 11:30:00', 60, '2026-10-17 12:00:00'],
            'confirmado in the future' => ['confirmado', '2026-10-17 15:00:00', 60, '2026-10-17 12:00:00'],
            'confirmado already ended' => ['confirmado', '2026-10-17 08:00:00', 60, '2026-10-17 12:00:00'],
            'confirmado starts exactly now' => ['confirmado', '2026-10-17 12:00:00', 60, '2026-10-17 12:00:00'],
            'confirmado ends exactly now' => ['confirmado', '2026-10-17 11:00:00', 60, '2026-10-17 12:00:00'],
            'confirmado one second before the end' => ['confirmado', '2026-10-17 11:00:01', 60, '2026-10-17 12:00:00'],
            'completado during its own interval' => ['completado', '2026-10-17 11:30:00', 60, '2026-10-17 12:00:00'],
            'completado in the past' => ['completado', '2026-10-10 11:30:00', 60, '2026-10-17 12:00:00'],
            'cancelado is returned as persisted' => ['cancelado', '2026-10-17 11:30:00', 60, '2026-10-17 12:00:00'],
        ];
    }

    #[DataProvider('tablaEstadoVisual')]
    public function test_estado_visual_matches_the_owner_agenda_rule(string $estado, string $fechaHora, int $duracion, string $ahora): void
    {
        Carbon::setTestNow($ahora);
        $turno = $this->turno(['estado' => $estado, 'fecha_hora' => $fechaHora, 'duracion_total_minutos' => $duracion]);

        $referencia = new class
        {
            use DecoraListadoDeTurnos;

            public function estado(Turno $turno): string
            {
                return $this->calcularEstadoVisual($turno);
            }
        };

        $esperado = $referencia->estado($turno);

        $this->assertSame($esperado, $this->mapear($turno)['estado_visual']);
    }

    public function test_estado_visual_values_for_the_two_states_a_professional_can_see(): void
    {
        Carbon::setTestNow('2026-10-17 12:00:00');

        $this->assertSame('en_curso', $this->mapear($this->turno(['fecha_hora' => '2026-10-17 11:30:00']))['estado_visual']);
        $this->assertSame('confirmado', $this->mapear($this->turno(['fecha_hora' => '2026-10-17 15:00:00']))['estado_visual']);
        $this->assertSame('completado', $this->mapear($this->turno(['estado' => 'completado']))['estado_visual']);
    }
}

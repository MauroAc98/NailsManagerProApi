<?php

namespace Tests\Feature\Pro;

use App\Http\Requests\Pro\MarcasTurnosProfesionalRequest;
use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * GET /api/pro/turnos/marcas?mes=YYYY-MM (AG-08, NF-PERF-1). One mark per day
 * on which SHE has at least one visible turno. Days are the wall-clock days of
 * the application timezone (America/Argentina/Buenos_Aires, never UTC), so the
 * boundary cases below sit at 23:59:59 and 00:00:00 of the first and last day.
 */
class ProTurnosMarcasTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private array $negocio;

    private ProfesionalCuenta $cuenta;

    private ProfesionalVinculo $vinculo;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 12:00:00');
        $this->negocio = $this->crearNegocioPortal();
        $this->cuenta = $this->crearCuentaPortal();
        $this->vinculo = $this->vincularPortal($this->cuenta, $this->negocio['ana']);
        $this->token = $this->tokenPara($this->cuenta);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $query */
    private function marcas(array $query = [], ?int $vinculoId = null, ?string $token = null): TestResponse
    {
        // Sanctum's guard caches the first resolved user per app instance.
        $this->app['auth']->forgetGuards();
        $uri = '/api/pro/turnos/marcas'.($query === [] ? '' : '?'.http_build_query($query));

        return $this->withToken($token ?? $this->token)
            ->getJson($uri, ['X-Vinculo-Id' => (string) ($vinculoId ?? $this->vinculo->id)]);
    }

    private function mes(string $mes): TestResponse
    {
        return $this->marcas(['mes' => $mes]);
    }

    // ── shape and counting ───────────────────────────────────────

    public function test_it_counts_her_turnos_per_day_in_ascending_date_order(): void
    {
        // Inserted out of order on purpose: the order is by date, not by id.
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-09 15:00:00');
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-03 17:30:00');
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-03 09:00:00');

        $r = $this->mes('2026-10')->assertOk();

        $this->assertSame([
            'mes' => '2026-10',
            'dias' => [
                ['fecha' => '2026-10-03', 'cantidad' => 2],
                ['fecha' => '2026-10-09', 'cantidad' => 1],
            ],
        ], $r->json());
    }

    public function test_the_response_has_exactly_the_agreed_keys(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-03 09:00:00');

        $r = $this->mes('2026-10')->assertOk();

        $this->assertEqualsCanonicalizing(['mes', 'dias'], array_keys($r->json()));
        $this->assertEqualsCanonicalizing(['fecha', 'cantidad'], array_keys($r->json('dias.0')));
        $this->assertIsInt($r->json('dias.0.cantidad'));
    }

    public function test_dias_is_a_list_of_objects_even_with_a_single_day(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-20 09:00:00');

        $contenido = $this->mes('2026-10')->assertOk()->getContent();

        // A list, never an associative map keyed by date.
        $this->assertSame('{"mes":"2026-10","dias":[{"fecha":"2026-10-20","cantidad":1}]}', $contenido);
    }

    public function test_an_empty_month_answers_dias_as_an_empty_json_list(): void
    {
        // Turnos exist, but in the neighbouring months only.
        $this->crearTurnoPortal($this->negocio['ana'], '2026-09-30 23:59:59');
        $this->crearTurnoPortal($this->negocio['ana'], '2026-11-01 00:00:00');

        $contenido = $this->mes('2026-10')->assertOk()->getContent();

        // "[]", not "{}": an empty PHP array must not turn into an object.
        $this->assertSame('{"mes":"2026-10","dias":[]}', $contenido);
    }

    public function test_confirmed_and_completed_turnos_both_count(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-05 09:00:00');
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-05 11:00:00', ['estado' => 'completado']);

        $this->assertSame(
            [['fecha' => '2026-10-05', 'cantidad' => 2]],
            $this->mes('2026-10')->assertOk()->json('dias'),
        );
    }

    // ── cancelled turnos are not visible, so they are not marks either ──

    public function test_cancelled_turnos_are_not_counted_and_a_day_with_only_cancelled_ones_is_absent(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-03 09:00:00');
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-03 11:00:00', ['estado' => 'cancelado', 'motivo_cancelacion' => 'x']);
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-04 09:00:00', ['estado' => 'cancelado', 'motivo_cancelacion' => 'x']);

        $this->assertSame(
            [['fecha' => '2026-10-03', 'cantidad' => 1]],
            $this->mes('2026-10')->assertOk()->json('dias'),
        );
    }

    // ── scope: only hers ─────────────────────────────────────────

    public function test_turnos_of_other_professionals_and_other_negocios_are_not_counted(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-03 09:00:00');
        $this->crearTurnoPortal($this->negocio['bea'], '2026-10-03 10:00:00');
        $this->crearTurnoPortal($this->negocio['titular'], '2026-10-04 10:00:00');
        $otroNegocio = $this->crearNegocioPortal();
        $this->crearTurnoPortal($otroNegocio['ana'], '2026-10-03 11:00:00');
        $this->crearTurnoPortal($otroNegocio['ana'], '2026-10-06 11:00:00');

        $this->assertSame(
            [['fecha' => '2026-10-03', 'cantidad' => 1]],
            $this->mes('2026-10')->assertOk()->json('dias'),
        );
    }

    public function test_a_row_with_her_professional_id_but_another_negocio_user_id_is_not_counted(): void
    {
        // Inconsistent data should not exist; if it ever does, the user_id guard keeps it out.
        $otroNegocio = $this->crearNegocioPortal();
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-03 09:00:00', ['user_id' => $otroNegocio['user']->id]);
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-04 09:00:00');

        $this->assertSame(
            [['fecha' => '2026-10-04', 'cantidad' => 1]],
            $this->mes('2026-10')->assertOk()->json('dias'),
        );
    }

    public function test_professional_and_user_ids_sent_in_the_query_are_ignored(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-03 09:00:00');
        $this->crearTurnoPortal($this->negocio['bea'], '2026-10-07 09:00:00');
        $otroNegocio = $this->crearNegocioPortal();
        $this->crearTurnoPortal($otroNegocio['ana'], '2026-10-08 09:00:00');

        $r = $this->marcas([
            'mes' => '2026-10',
            'profesional_id' => $this->negocio['bea']->id,
            'user_id' => $otroNegocio['user']->id,
            'cliente_id' => 1,
            'negocio_id' => $otroNegocio['user']->id,
        ])->assertOk();

        $this->assertSame([['fecha' => '2026-10-03', 'cantidad' => 1]], $r->json('dias'));
    }

    // ── day and month boundaries, with app.timezone not UTC ──────

    public function test_the_application_timezone_is_not_utc(): void
    {
        // Guards the premise of the boundary tests below.
        $this->assertSame('America/Argentina/Buenos_Aires', config('app.timezone'));
    }

    public function test_the_last_second_of_the_month_counts_in_that_month_and_not_in_the_next(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-31 23:59:59');

        $this->assertSame(
            [['fecha' => '2026-10-31', 'cantidad' => 1]],
            $this->mes('2026-10')->assertOk()->json('dias'),
        );
        $this->assertSame('{"mes":"2026-11","dias":[]}', $this->mes('2026-11')->assertOk()->getContent());
    }

    public function test_the_first_second_of_the_month_counts_in_that_month_and_not_in_the_previous(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-11-01 00:00:00');

        $this->assertSame('{"mes":"2026-10","dias":[]}', $this->mes('2026-10')->assertOk()->getContent());
        $this->assertSame(
            [['fecha' => '2026-11-01', 'cantidad' => 1]],
            $this->mes('2026-11')->assertOk()->json('dias'),
        );
    }

    public function test_a_late_evening_turno_counts_on_its_local_day_never_on_the_next_one(): void
    {
        // 22:00 in Buenos Aires is 01:00 of the NEXT day in UTC.
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 22:00:00');
        // 00:30 local is 03:30 UTC of the same day: the early side of the shift.
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-18 00:30:00');

        $this->assertSame(
            [
                ['fecha' => '2026-10-17', 'cantidad' => 1],
                ['fecha' => '2026-10-18', 'cantidad' => 1],
            ],
            $this->mes('2026-10')->assertOk()->json('dias'),
        );
    }

    public function test_a_late_evening_turno_on_the_last_day_of_the_month_does_not_leak_into_the_next_month(): void
    {
        // 22:00 local on Oct 31 is Nov 1 01:00 in UTC: a UTC-based window would move it.
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-31 22:00:00');

        $this->assertSame(
            [['fecha' => '2026-10-31', 'cantidad' => 1]],
            $this->mes('2026-10')->assertOk()->json('dias'),
        );
        $this->assertSame([], $this->mes('2026-11')->assertOk()->json('dias'));
    }

    public function test_the_year_boundary_holds_at_both_ends(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-12-31 23:59:59');
        $this->crearTurnoPortal($this->negocio['ana'], '2027-01-01 00:00:00');

        $this->assertSame(
            [['fecha' => '2026-12-31', 'cantidad' => 1]],
            $this->mes('2026-12')->assertOk()->json('dias'),
        );
        $this->assertSame(
            [['fecha' => '2027-01-01', 'cantidad' => 1]],
            $this->mes('2027-01')->assertOk()->json('dias'),
        );
    }

    public function test_february_of_a_leap_year_ends_on_the_29th(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2028-02-29 23:59:59');
        $this->crearTurnoPortal($this->negocio['ana'], '2028-03-01 00:00:00');

        $this->assertSame(
            [['fecha' => '2028-02-29', 'cantidad' => 1]],
            $this->mes('2028-02')->assertOk()->json('dias'),
        );
        $this->assertSame(
            [['fecha' => '2028-03-01', 'cantidad' => 1]],
            $this->mes('2028-03')->assertOk()->json('dias'),
        );
    }

    public function test_marks_do_not_depend_on_the_current_time_zone_of_the_clock(): void
    {
        // "Now" in UTC is already Nov 1; the month asked for is still decided by the stored wall clock.
        Carbon::setTestNow(Carbon::parse('2026-11-01 02:00:00', 'UTC'));
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-31 23:30:00');

        $this->assertSame(
            [['fecha' => '2026-10-31', 'cantidad' => 1]],
            $this->mes('2026-10')->assertOk()->json('dias'),
        );
    }

    // ── validation of `mes` ──────────────────────────────────────

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function mesesInvalidos(): array
    {
        return [
            'missing' => [[]],
            'empty' => [['mes' => '']],
            'month 13' => [['mes' => '2026-13']],
            'month 00' => [['mes' => '2026-00']],
            'one digit month' => [['mes' => '2026-1']],
            'full date' => [['mes' => '2026-10-01']],
            'two digit year' => [['mes' => '26-10']],
            'year 0000' => [['mes' => '0000-01']],
            'before 2000' => [['mes' => '1999-12']],
            'five digit year' => [['mes' => '20260-10']],
            'words' => [['mes' => 'octubre']],
            'slash separator' => [['mes' => '2026/10']],
            'array' => [['mes' => ['2026-10']]],
        ];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('mesesInvalidos')]
    public function test_a_missing_or_malformed_month_is_a_validation_error(array $query): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-03 09:00:00');

        $this->marcas($query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mes']);
    }

    public function test_validation_messages_are_in_spanish_and_say_what_is_wrong(): void
    {
        $this->assertSame(
            'Indicá el mes con el formato AAAA-MM.',
            $this->marcas()->assertUnprocessable()->json('errors.mes.0'),
        );
        $this->assertSame(
            'El mes tiene que tener el formato AAAA-MM.',
            $this->mes('2026-13')->assertUnprocessable()->json('errors.mes.0'),
        );
        $this->assertSame(
            'El mes tiene que ser posterior al año 2000.',
            $this->mes('1999-12')->assertUnprocessable()->json('errors.mes.0'),
        );
    }

    public function test_the_lowest_accepted_month_is_january_2000(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2000-01-15 09:00:00');

        $this->assertSame(
            [['fecha' => '2000-01-15', 'cantidad' => 1]],
            $this->mes('2000-01')->assertOk()->json('dias'),
        );
    }

    public function test_the_highest_four_digit_month_does_not_break_the_window(): void
    {
        $this->assertSame('{"mes":"9999-12","dias":[]}', $this->mes('9999-12')->assertOk()->getContent());
    }

    public function test_the_month_regex_is_anchored_with_end_of_string_not_dollar(): void
    {
        // TrimStrings removes a trailing newline before it gets to the rule over
        // HTTP, so the rule itself is exercised directly: `$` would accept it.
        $reglas = (new MarcasTurnosProfesionalRequest)->rules();

        $this->assertTrue(Validator::make(['mes' => '2026-10'], $reglas)->passes());
        $this->assertFalse(Validator::make(['mes' => "2026-10\n"], $reglas)->passes());
        $this->assertFalse(Validator::make(['mes' => "\n2026-10"], $reglas)->passes());
    }

    // ── link, token and middleware stack ─────────────────────────

    public function test_without_the_link_header_it_is_422_vinculo_requerido(): void
    {
        $this->app['auth']->forgetGuards();

        $this->withToken($this->token)
            ->getJson('/api/pro/turnos/marcas?mes=2026-10')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VINCULO_REQUERIDO');
    }

    public function test_the_link_of_another_account_is_403_with_the_same_body_as_a_nonexistent_link(): void
    {
        $otraCuenta = $this->crearCuentaPortal('otra@x.com');
        $ajeno = $this->vincularPortal($otraCuenta, $this->negocio['bea']);

        $ajena = $this->marcas(['mes' => '2026-10'], $ajeno->id)->assertForbidden();
        $inexistente = $this->marcas(['mes' => '2026-10'], 999999)->assertForbidden();

        $this->assertSame($inexistente->getContent(), $ajena->getContent());
        $this->assertSame('VINCULO_NO_DISPONIBLE', $ajena->json('code'));
    }

    public function test_a_negocio_with_a_lapsed_subscription_gets_the_tenant_code(): void
    {
        $sinSuscripcion = $this->crearNegocioPortal(['is_exempt' => false]);
        $vinculo = $this->vincularPortal($this->crearCuentaPortal('sinsub@x.com'), $sinSuscripcion['ana']);
        $token = $this->tokenPara($vinculo->cuenta);

        $this->marcas(['mes' => '2026-10'], $vinculo->id, $token)
            ->assertForbidden()
            ->assertJsonPath('code', 'NO_SUBSCRIPTION');
    }

    public function test_it_is_not_reachable_without_a_token_and_never_with_a_write_method(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/pro/turnos/marcas?mes=2026-10')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)
            ->postJson('/api/pro/turnos/marcas', ['mes' => '2026-10'], ['X-Vinculo-Id' => (string) $this->vinculo->id])
            ->assertStatus(405);
    }

    public function test_the_response_is_not_cacheable(): void
    {
        $this->assertStringContainsString('no-store', $this->mes('2026-10')->assertOk()->headers->get('Cache-Control'));
    }

    // ── route order: `marcas` must not be swallowed by `turnos/{id}` ──

    public function test_marcas_is_registered_above_the_detail_route(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($ruta) => in_array('GET', $ruta->methods(), true))
            ->map(fn ($ruta) => $ruta->uri())
            ->values();

        $marcas = $uris->search('api/pro/turnos/marcas');
        $detalle = $uris->search('api/pro/turnos/{id}');

        $this->assertNotFalse($marcas);
        $this->assertNotFalse($detalle);
        $this->assertLessThan($detalle, $marcas);
    }

    public function test_marcas_is_answered_by_the_marks_endpoint_and_not_by_the_detail_one(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-03 09:00:00');

        $r = $this->mes('2026-10')->assertOk();

        $this->assertSame('2026-10', $r->json('mes'));
        $this->assertStringNotContainsString('TURNO_NO_ENCONTRADO', $r->getContent());
        // Without `mes` it is the marks validation (422), not the detail 404 body.
        $this->marcas()->assertUnprocessable()->assertJsonMissing(['code' => 'TURNO_NO_ENCONTRADO']);
    }

    public function test_the_detail_route_still_answers_numeric_ids_after_marcas(): void
    {
        $turno = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-03 09:00:00');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)
            ->getJson('/api/pro/turnos/'.$turno->id, ['X-Vinculo-Id' => (string) $this->vinculo->id])
            ->assertOk()
            ->assertJsonPath('id', $turno->id);
    }

    // ── what it reads and what it exposes ────────────────────────

    public function test_it_reads_only_the_fecha_hora_column_with_a_single_query_on_turnos(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-03 09:00:00');
        $this->mes('2026-10')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->mes('2026-10')->assertOk();

        $sobreTurnos = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn ($q) => preg_match('/\bfrom "turnos"/i', $q) === 1)
            ->values();

        $this->assertCount(1, $sobreTurnos);
        // Nothing but the timestamp is selected: no notes, prices, client or phone.
        $this->assertStringStartsWith('select "fecha_hora" from "turnos"', strtolower($sobreTurnos[0]));
    }

    public function test_the_query_carries_an_explicit_order_by_fecha_hora(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-03 09:00:00');
        $this->mes('2026-10')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->mes('2026-10')->assertOk();

        $consulta = collect(DB::getQueryLog())
            ->pluck('query')
            ->first(fn ($q) => preg_match('/\bfrom "turnos"/i', $q) === 1);
        // SQLite happens to walk the fecha_hora index in order; Postgres guarantees nothing without ORDER BY.
        $this->assertStringContainsString('order by "fecha_hora" asc', strtolower($consulta));
    }

    public function test_the_response_carries_no_sensitive_value_of_the_turnos(): void
    {
        $cliente = $this->crearClienteConCanarios();
        $servicio = $this->crearServicioPortal($this->negocio['user'], 'ServicioCanario', ['precio' => 54321]);
        $turno = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-03 09:00:00', [
            'cliente_id' => $cliente->id,
            'notas' => 'nota-canario-privada',
        ]);
        $turno->servicios()->attach($servicio->id, ['precio' => 98765]);
        $this->crearTurnoPortal($this->negocio['bea'], '2026-10-03 10:00:00', ['notas' => 'nota-ajena-canario']);

        $contenido = $this->mes('2026-10')->assertOk()->getContent();

        $this->assertSame('{"mes":"2026-10","dias":[{"fecha":"2026-10-03","cantidad":1}]}', $contenido);
        foreach (['+5491199887766', '1199887766', 'ClienteCanario', 'nota-canario-privada', 'nota-ajena-canario', 'ServicioCanario', '54321', '98765', 'Bea'] as $canario) {
            $this->assertStringNotContainsString($canario, $contenido);
        }
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_turnos(): void
    {
        $this->insertarTurnosDeAna(1);
        [$consultasCon1, $diasCon1] = $this->contarConsultas();

        $this->insertarTurnosDeAna(99);
        [$consultasCon100, $diasCon100] = $this->contarConsultas();

        $this->assertSame(1, $diasCon1);
        $this->assertSame(1, $diasCon100);
        $this->assertSame(100, $this->mes('2026-10')->json('dias.0.cantidad'));
        $this->assertSame($consultasCon1, $consultasCon100);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_days_with_turnos(): void
    {
        $this->insertarTurnosDeAna(1);
        [$consultasCon1] = $this->contarConsultas();

        for ($dia = 4; $dia <= 28; $dia++) {
            $this->insertarTurnosDeAna(3, $dia);
        }
        [$consultasCon26Dias, $dias] = $this->contarConsultas();

        $this->assertSame(26, $dias);
        $this->assertSame($consultasCon1, $consultasCon26Dias);
    }

    /** @return array{0: int, 1: int} queries issued by one request, days returned */
    private function contarConsultas(): array
    {
        // Warm-up: Sanctum writes last_used_at only on the first use of a token,
        // which would make the first measurement one query heavier.
        $this->mes('2026-10')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $respuesta = $this->mes('2026-10')->assertOk();

        return [count(DB::getQueryLog()), count($respuesta->json('dias'))];
    }

    /** Bulk insert (chunked: sqlite caps compound selects) of $cantidad turnos of Ana on October $dia. */
    private function insertarTurnosDeAna(int $cantidad, int $dia = 3): void
    {
        $filas = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $filas[] = [
                'user_id' => $this->negocio['user']->id,
                'profesional_id' => $this->negocio['ana']->id,
                'fecha_hora' => sprintf('2026-10-%02d %02d:%02d:00', $dia, intdiv($i, 60), $i % 60),
                'duracion_total_minutos' => 15,
                'estado' => 'confirmado',
                'origen' => 'app',
                'created_at' => '2026-10-01 00:00:00',
                'updated_at' => '2026-10-01 00:00:00',
            ];
        }
        foreach (array_chunk($filas, 50) as $trozo) {
            DB::table('turnos')->insert($trozo);
        }
    }

    private function crearClienteConCanarios()
    {
        return $this->crearClientePortal($this->negocio['user'], 'ClienteCanario', 'Apellido', '+5491199887766');
    }
}

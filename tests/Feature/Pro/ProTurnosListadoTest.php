<?php

namespace Tests\Feature\Pro;

use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
use App\Models\TurnoGrupo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * GET /api/pro/turnos (AG-01, AG-02, NF-PERF-2). The professional comes from
 * the link only; the default window is "today" in the application timezone.
 * "Now" is 2026-10-17 12:00 in Buenos Aires unless a test says otherwise.
 */
class ProTurnosListadoTest extends TestCase
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
    private function listar(array $query = [], ?int $vinculoId = null, ?string $token = null): TestResponse
    {
        // Sanctum's guard caches the first resolved user per app instance.
        $this->app['auth']->forgetGuards();
        $uri = '/api/pro/turnos'.($query === [] ? '' : '?'.http_build_query($query));

        return $this->withToken($token ?? $this->token)
            ->getJson($uri, ['X-Vinculo-Id' => (string) ($vinculoId ?? $this->vinculo->id)]);
    }

    /** @return list<int> */
    private function ids(TestResponse $respuesta): array
    {
        return array_column($respuesta->json('data'), 'id');
    }

    /** Bulk insert (chunked: sqlite caps compound selects) of $cantidad turnos of Ana. */
    private function insertarTurnosDeAna(int $cantidad, string $dia = '2026-10-17'): void
    {
        $filas = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $filas[] = [
                'user_id' => $this->negocio['user']->id,
                'profesional_id' => $this->negocio['ana']->id,
                'fecha_hora' => sprintf('%s %02d:%02d:00', $dia, intdiv($i, 60), $i % 60),
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

    // ── scope: whose turnos are visible ──────────────────────────

    public function test_it_lists_only_her_confirmed_and_completed_turnos(): void
    {
        $suyo = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 09:00:00');
        $completado = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 08:00:00', ['estado' => 'completado']);
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 10:00:00', ['estado' => 'cancelado', 'motivo_cancelacion' => 'x']);
        $this->crearTurnoPortal($this->negocio['bea'], '2026-10-17 09:30:00');
        $otroNegocio = $this->crearNegocioPortal();
        $this->crearTurnoPortal($otroNegocio['ana'], '2026-10-17 09:45:00');

        $r = $this->listar()->assertOk();

        $this->assertSame([$completado->id, $suyo->id], $this->ids($r));
        $this->assertSame('completado', $r->json('data.0.estado_visual'));
        $this->assertSame('confirmado', $r->json('data.1.estado_visual'));
    }

    public function test_a_turno_of_another_professional_with_the_same_negocio_user_id_never_appears(): void
    {
        // Same user_id as Ana's negocio (so user_id alone would let it through).
        $this->crearTurnoPortal($this->negocio['titular'], '2026-10-17 09:00:00');
        $this->assertSame(
            $this->negocio['user']->id,
            $this->negocio['titular']->user_id,
        );

        $this->assertSame([], $this->listar()->assertOk()->json('data'));
    }

    public function test_a_row_with_her_professional_id_but_another_negocio_user_id_is_not_shown(): void
    {
        // Inconsistent data should not exist; if it ever does, the user_id guard keeps it out.
        $otroNegocio = $this->crearNegocioPortal();
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 09:00:00', ['user_id' => $otroNegocio['user']->id]);
        $suyo = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 10:00:00');

        $this->assertSame([$suyo->id], $this->ids($this->listar()->assertOk()));
    }

    public function test_the_user_id_guard_also_applies_to_the_detail(): void
    {
        $otroNegocio = $this->crearNegocioPortal();
        $incoherente = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 09:00:00', ['user_id' => $otroNegocio['user']->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)
            ->getJson("/api/pro/turnos/{$incoherente->id}", ['X-Vinculo-Id' => (string) $this->vinculo->id])
            ->assertStatus(404);
    }
    public function test_user_id_and_profesional_id_in_the_query_string_are_ignored(): void
    {
        $suyo = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 09:00:00');
        $deBea = $this->crearTurnoPortal($this->negocio['bea'], '2026-10-17 09:30:00');
        $otroNegocio = $this->crearNegocioPortal();
        $ajeno = $this->crearTurnoPortal($otroNegocio['ana'], '2026-10-17 09:45:00');

        $r = $this->listar([
            'profesional_id' => $deBea->profesional_id,
            'user_id' => $otroNegocio['user']->id,
            'cliente_id' => 1,
        ])->assertOk();

        $this->assertSame([$suyo->id], $this->ids($r));
        $this->assertNotContains($deBea->id, $this->ids($r));
        $this->assertNotContains($ajeno->id, $this->ids($r));
    }

    public function test_the_link_header_selects_the_professional_with_the_same_token(): void
    {
        $otroNegocio = $this->crearNegocioPortal();
        $otroVinculo = $this->vincularPortal($this->cuenta, $otroNegocio['ana']);
        $primero = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 09:00:00');
        $segundo = $this->crearTurnoPortal($otroNegocio['ana'], '2026-10-17 09:00:00');

        $this->assertSame([$primero->id], $this->ids($this->listar([], $this->vinculo->id)));
        $this->assertSame([$segundo->id], $this->ids($this->listar([], $otroVinculo->id)));
    }

    // ── gate: the route runs the full pro.vigente stack ──────────

    public function test_a_missing_link_header_is_a_422_and_a_foreign_link_a_403(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/api/pro/turnos')
            ->assertStatus(422)->assertJsonPath('code', 'VINCULO_REQUERIDO');

        $ajena = $this->crearCuentaPortal('bea@x.com');
        $vinculoAjeno = $this->vincularPortal($ajena, $this->negocio['bea']);

        $this->listar([], $vinculoAjeno->id)->assertStatus(403)->assertJsonPath('code', 'VINCULO_NO_DISPONIBLE');
    }

    public function test_the_route_stack_has_throttle_before_vigente_and_no_write_methods(): void
    {
        $ruta = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/pro/turnos' && in_array('GET', $r->methods(), true));

        $this->assertNotNull($ruta);
        $esperado = ['portal.pro', 'auth:profesional', 'abilities:pro:acceso', 'throttle:pro', 'pro.vigente'];
        $this->assertSame($esperado, array_values(array_intersect($ruta->gatherMiddleware(), $esperado)));

        $this->app['auth']->forgetGuards();
        $cabeceras = ['X-Vinculo-Id' => (string) $this->vinculo->id];
        $this->withToken($this->token)->postJson('/api/pro/turnos', [], $cabeceras)->assertStatus(405);
        $this->withToken($this->token)->deleteJson('/api/pro/turnos', [], $cabeceras)->assertStatus(405);
    }

    // ── shape ────────────────────────────────────────────────────

    public function test_the_response_is_an_envelope_with_data_and_truncated_and_the_item_is_whitelisted(): void
    {
        $cliente = $this->crearClientePortal($this->negocio['user']);
        $servicio = $this->crearServicioPortal($this->negocio['user']);
        $turno = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 09:00:00', ['cliente_id' => $cliente->id, 'notas' => 'Diseño simple', 'duracion_total_minutos' => 45]);
        $turno->servicios()->attach($servicio->id, ['precio' => 12345]);

        $r = $this->listar()->assertOk();

        $this->assertEqualsCanonicalizing(['data', 'truncated'], array_keys($r->json()));
        $this->assertFalse($r->json('truncated'));
        $this->assertSame([[
            'id' => $turno->id,
            'fecha_hora' => '2026-10-17T09:00:00',
            'duracion_total_minutos' => 45,
            'estado_visual' => 'confirmado',
            'notas' => 'Diseño simple',
            'cliente' => ['nombre' => 'Maria', 'apellido' => 'Lopez'],
            'servicios' => [['id' => $servicio->id, 'nombre' => 'Capping']],
            'promo' => null,
        ]], $r->json('data'));
    }

    public function test_a_promo_turno_shows_the_promo_name_and_only_her_own_step(): void
    {
        $promo = $this->crearServicioPortal($this->negocio['user'], 'Manicura + Pedicura', ['es_promo' => true]);
        $manicura = $this->crearServicioPortal($this->negocio['user'], 'Manicura');
        $pedicura = $this->crearServicioPortal($this->negocio['user'], 'Pedicura spa');
        $grupo = TurnoGrupo::create(['modo' => 'secuencia', 'promo_servicio_id' => $promo->id, 'precio_promo' => 18000]);
        $suyo = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 10:00:00', ['grupo_id' => $grupo->id]);
        $suyo->servicios()->attach($manicura->id);
        $deBea = $this->crearTurnoPortal($this->negocio['bea'], '2026-10-17 11:00:00', ['grupo_id' => $grupo->id]);
        $deBea->servicios()->attach($pedicura->id);

        $r = $this->listar()->assertOk();

        $this->assertSame([$suyo->id], $this->ids($r));
        $this->assertSame(['nombre' => 'Manicura + Pedicura'], $r->json('data.0.promo'));
        $this->assertSame([['id' => $manicura->id, 'nombre' => 'Manicura']], $r->json('data.0.servicios'));
        $this->assertStringNotContainsString('Pedicura spa', $r->getContent());
        $this->assertStringNotContainsString('Bea', $r->getContent());
    }

    // ── date window ──────────────────────────────────────────────

    public function test_without_a_date_filter_it_lists_today_only(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-16 23:59:59');
        $hoy = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 00:00:00');
        $hoyTarde = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 23:59:59');
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-18 00:00:00');

        $this->assertSame([$hoy->id, $hoyTarde->id], $this->ids($this->listar()->assertOk()));
    }

    public function test_today_is_the_local_day_not_the_utc_day(): void
    {
        // 01:30 UTC on the 17th is 22:30 on the 16th in Buenos Aires.
        Carbon::setTestNow(Carbon::parse('2026-10-17 01:30:00', 'UTC'));
        $this->token = $this->tokenPara($this->cuenta);
        $ayerLocal = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-16 20:00:00');
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 20:00:00');

        $this->assertSame([$ayerLocal->id], $this->ids($this->listar()->assertOk()));
    }

    public function test_fecha_filters_one_day_with_midnight_boundaries(): void
    {
        $finDelDia10 = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-10 23:59:59');
        $inicioDelDia11 = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-11 00:00:00');
        $inicioDelDia10 = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-10 00:00:00');
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-09 23:59:59');

        $this->assertSame([$inicioDelDia10->id, $finDelDia10->id], $this->ids($this->listar(['fecha' => '2026-10-10'])));
        $this->assertSame([$inicioDelDia11->id], $this->ids($this->listar(['fecha' => '2026-10-11'])));
    }

    public function test_a_malformed_fecha_is_a_422_instead_of_a_silent_overflow(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2027-02-14 10:00:00');

        $this->listar(['fecha' => '2026-13-45'])->assertStatus(422)->assertJsonValidationErrors('fecha');
        $this->listar(['fecha' => 'ayer'])->assertStatus(422);
        $this->listar(['fecha' => ['2026-10-17']])->assertStatus(422);
    }

    // ── order and cap ────────────────────────────────────────────

    public function test_it_orders_by_fecha_hora_then_id(): void
    {
        $tarde = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 15:00:00');
        $mismaHoraA = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 09:00:00');
        $mismaHoraB = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 09:00:00');
        $temprano = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 07:00:00');

        $this->assertSame(
            [$temprano->id, $mismaHoraA->id, $mismaHoraB->id, $tarde->id],
            $this->ids($this->listar()->assertOk()),
        );
    }

    public function test_the_list_and_services_queries_carry_an_explicit_order_by(): void
    {
        $turno = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 09:00:00');
        $turno->servicios()->attach($this->crearServicioPortal($this->negocio['user'])->id);
        $this->listar()->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->listar()->assertOk();

        $consulta = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_starts_with($q, 'select * from "turnos"'));
        $this->assertNotNull($consulta);
        $this->assertStringContainsString('order by "fecha_hora" asc, "id" asc limit 501', $consulta);
        // SQLite happens to return rows in id order; Postgres guarantees nothing without ORDER BY.
        $servicios = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_contains($q, 'from "servicios"'));
        $this->assertStringContainsString('order by "servicios"."id" asc', $servicios);
    }

    public function test_services_of_a_turno_come_ordered_by_id_whatever_the_attach_order(): void
    {
        $primero = $this->crearServicioPortal($this->negocio['user'], 'Primero');
        $segundo = $this->crearServicioPortal($this->negocio['user'], 'Segundo');
        $tercero = $this->crearServicioPortal($this->negocio['user'], 'Tercero');
        $turno = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 09:00:00');
        $turno->servicios()->attach($tercero->id);
        $turno->servicios()->attach($primero->id);
        $turno->servicios()->attach($segundo->id);

        $r = $this->listar()->assertOk();

        $this->assertSame([$primero->id, $segundo->id, $tercero->id], array_column($r->json('data.0.servicios'), 'id'));
    }
    public function test_exactly_500_turnos_are_not_truncated(): void
    {
        $this->insertarTurnosDeAna(500);

        $r = $this->listar()->assertOk();

        $this->assertCount(500, $r->json('data'));
        $this->assertFalse($r->json('truncated'));
    }

    public function test_501_turnos_return_the_first_500_and_flag_truncated(): void
    {
        $this->insertarTurnosDeAna(501);

        $r = $this->listar()->assertOk();

        $this->assertCount(500, $r->json('data'));
        $this->assertTrue($r->json('truncated'));
        // The cut keeps the earliest 500: the 501st (08:20) is the one dropped.
        $this->assertSame('2026-10-17T08:19:00', $r->json('data.499.fecha_hora'));
    }

    // ── queries ──────────────────────────────────────────────────

    private function crearTurnoCompleto(int $i): void
    {
        $user = $this->negocio['user'];
        $cliente = $this->crearClientePortal($user, 'Cliente'.$i, 'Apellido'.$i, '+54911555'.str_pad((string) $i, 5, '0', STR_PAD_LEFT));
        $promo = $this->crearServicioPortal($user, 'Promo '.$i, ['es_promo' => true]);
        $grupo = TurnoGrupo::create(['modo' => 'secuencia', 'promo_servicio_id' => $promo->id]);
        $turno = $this->crearTurnoPortal($this->negocio['ana'], sprintf('2026-10-17 %02d:%02d:00', intdiv($i, 60), $i % 60), [
            'cliente_id' => $cliente->id,
            'grupo_id' => $grupo->id,
        ]);
        $turno->servicios()->attach([
            $this->crearServicioPortal($user, 'Uno '.$i)->id,
            $this->crearServicioPortal($user, 'Dos '.$i)->id,
        ]);
    }

    private function contarConsultas(): array
    {
        // Warm-up: Sanctum writes last_used_at only on the first use of a token,
        // which would make the first measurement one query heavier.
        $this->listar()->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $respuesta = $this->listar()->assertOk();

        return [count(DB::getQueryLog()), count($respuesta->json('data'))];
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_turnos(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->crearTurnoCompleto($i);
        }
        [$consultasCon5, $filasCon5] = $this->contarConsultas();

        for ($i = 5; $i < 50; $i++) {
            $this->crearTurnoCompleto($i);
        }
        [$consultasCon50, $filasCon50] = $this->contarConsultas();

        $this->assertSame(5, $filasCon5);
        $this->assertSame(50, $filasCon50);
        $this->assertSame($consultasCon5, $consultasCon50);
    }
}

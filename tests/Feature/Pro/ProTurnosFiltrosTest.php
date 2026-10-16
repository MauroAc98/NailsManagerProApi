<?php

namespace Tests\Feature\Pro;

use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
use App\Models\Turno;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * Filters of GET /api/pro/turnos (AG-02 ranges, AG-03 search, AG-04 service).
 * Search must never become an oracle: it matches the client's name and surname
 * only, never phone, notes or services. "Now" is 2026-10-17 12:00 in Buenos Aires.
 */
class ProTurnosFiltrosTest extends TestCase
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
    private function listar(array $query = []): TestResponse
    {
        // Sanctum's guard caches the first resolved user per app instance.
        $this->app['auth']->forgetGuards();
        $uri = '/api/pro/turnos'.($query === [] ? '' : '?'.http_build_query($query));

        return $this->withToken($this->token)
            ->getJson($uri, ['X-Vinculo-Id' => (string) $this->vinculo->id]);
    }

    /** @return list<int> */
    private function ids(TestResponse $respuesta): array
    {
        return array_column($respuesta->json('data'), 'id');
    }

    // ── ranges (AG-02 S2-S4) ─────────────────────────────────────

    public function test_a_range_is_inclusive_on_both_ends_with_midnight_edges(): void
    {
        $antes = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-09 23:59:59');
        $primero = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-10 00:00:00');
        $medio = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-11 13:00:00');
        $ultimo = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-12 23:59:59');
        $despues = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-13 00:00:00');

        $respuesta = $this->listar(['desde' => '2026-10-10', 'hasta' => '2026-10-12'])->assertOk();

        $this->assertSame([$primero->id, $medio->id, $ultimo->id], $this->ids($respuesta));
        $this->assertNotContains($antes->id, $this->ids($respuesta));
        $this->assertNotContains($despues->id, $this->ids($respuesta));
        $this->assertFalse($respuesta->json('truncated'));
    }

    public function test_a_one_day_range_equals_the_fecha_filter(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-09 23:59:59');
        $dentro = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-10 08:00:00');
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-11 00:00:00');

        $this->assertSame([$dentro->id], $this->ids($this->listar(['desde' => '2026-10-10', 'hasta' => '2026-10-10'])->assertOk()));
        $this->assertSame([$dentro->id], $this->ids($this->listar(['fecha' => '2026-10-10'])->assertOk()));
    }

    public function test_a_range_never_widens_the_scope_to_other_professionals_or_cancelled_turnos(): void
    {
        $suyo = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-11 10:00:00');
        $this->crearTurnoPortal($this->negocio['bea'], '2026-10-11 10:00:00');
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-11 11:00:00', ['estado' => 'cancelado']);
        $otro = $this->crearNegocioPortal();
        $this->crearTurnoPortal($otro['ana'], '2026-10-11 12:00:00');

        $this->assertSame(
            [$suyo->id],
            $this->ids($this->listar(['desde' => '2026-10-10', 'hasta' => '2026-10-12'])->assertOk()),
        );
    }

    public function test_a_range_of_366_days_inclusive_is_accepted_and_367_is_a_422(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2027-01-01 09:00:00');

        // 2026-01-01..2027-01-01 are 366 days counting both ends.
        $ok = $this->listar(['desde' => '2026-01-01', 'hasta' => '2027-01-01'])->assertOk();
        $this->assertCount(1, $ok->json('data'));

        $this->listar(['desde' => '2026-01-01', 'hasta' => '2027-01-02'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('hasta');
    }

    #[DataProvider('rangosInvalidos')]
    public function test_invalid_date_combinations_are_a_422(array $query, string $campo): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 10:00:00');

        $this->listar($query)->assertStatus(422)->assertJsonValidationErrors($campo);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function rangosInvalidos(): array
    {
        return [
            // Postgres rejects year 0000 as out of range, so it must be a 422, never a 500.
            'fecha in year 0000' => [['fecha' => '0000-01-01'], 'fecha'],
            'desde in year 0000' => [['desde' => '0000-01-01', 'hasta' => '0000-02-01'], 'desde'],
            'hasta in year 0000' => [['desde' => '2026-10-10', 'hasta' => '0000-02-01'], 'hasta'],
            'fecha before 2000' => [['fecha' => '1999-12-31'], 'fecha'],
            'fecha with desde and hasta' => [['fecha' => '2026-10-17', 'desde' => '2026-10-10', 'hasta' => '2026-10-12'], 'fecha'],
            'fecha with desde only' => [['fecha' => '2026-10-17', 'desde' => '2026-10-10'], 'fecha'],
            'fecha with hasta only' => [['fecha' => '2026-10-17', 'hasta' => '2026-10-12'], 'fecha'],
            'desde without hasta' => [['desde' => '2026-10-10'], 'hasta'],
            'hasta without desde' => [['hasta' => '2026-10-10'], 'desde'],
            'hasta before desde' => [['desde' => '2026-10-12', 'hasta' => '2026-10-10'], 'hasta'],
            'impossible month in desde' => [['desde' => '2026-13-45', 'hasta' => '2026-12-01'], 'desde'],
            'impossible day in hasta' => [['desde' => '2026-02-01', 'hasta' => '2026-02-30'], 'hasta'],
            'free text in desde' => [['desde' => 'ayer', 'hasta' => '2026-10-17'], 'desde'],
            'array in hasta' => [['desde' => '2026-10-10', 'hasta' => ['2026-10-12']], 'hasta'],
            'fecha with a time part' => [['fecha' => '2026-10-17 10:00:00'], 'fecha'],
        ];
    }

    public function test_an_empty_desde_and_hasta_fall_back_to_today(): void
    {
        $hoy = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 10:00:00');
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-18 10:00:00');

        $this->assertSame([$hoy->id], $this->ids($this->listar(['desde' => '', 'hasta' => ''])->assertOk()));
    }

    public function test_the_range_still_obeys_the_500_row_cap(): void
    {
        $filas = [];
        for ($i = 0; $i < 501; $i++) {
            $filas[] = [
                'user_id' => $this->negocio['user']->id,
                'profesional_id' => $this->negocio['ana']->id,
                'fecha_hora' => sprintf('2026-10-%02d %02d:%02d:00', 10 + intdiv($i, 250), intdiv($i % 250, 60), $i % 60),
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

        $respuesta = $this->listar(['desde' => '2026-10-10', 'hasta' => '2026-10-12'])->assertOk();

        $this->assertCount(500, $respuesta->json('data'));
        $this->assertTrue($respuesta->json('truncated'));
    }

    // ── search: buscar (AG-03) ───────────────────────────────────

    /** The phone is unique per negocio. */
    private function telefonoUnico(): string
    {
        static $siguiente = 0;

        return '+54911555'.str_pad((string) ++$siguiente, 5, '0', STR_PAD_LEFT);
    }

    /** A turno of Ana today, with a client created on the spot. */
    private function turnoConCliente(string $nombre, ?string $apellido, array $turno = [], ?string $telefono = null): int
    {
        $cliente = $this->crearClientePortal($this->negocio['user'], $nombre, $apellido, $telefono ?? $this->telefonoUnico());

        return $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 10:00:00', array_merge(['cliente_id' => $cliente->id], $turno))->id;
    }

    public function test_buscar_ands_the_words_and_each_one_matches_nombre_or_apellido(): void
    {
        $maria = $this->turnoConCliente('Maria', 'Lopez');
        $this->turnoConCliente('Mariana', 'Perez');
        $this->turnoConCliente('Lopez', 'Gomez');

        $this->assertSame([$maria], $this->ids($this->listar(['buscar' => 'mar lop'])->assertOk()));
        // Order of the words does not matter and a word can hit nombre or apellido.
        $this->assertSame([$maria], $this->ids($this->listar(['buscar' => 'lop mar'])->assertOk()));
    }

    public function test_buscar_words_are_anded_not_ored(): void
    {
        $this->turnoConCliente('Maria', 'Perez');
        $this->turnoConCliente('Ana', 'Lopez');

        $this->assertSame([], $this->ids($this->listar(['buscar' => 'maria lopez'])->assertOk()));
    }

    public function test_buscar_can_match_two_words_that_are_both_in_the_nombre(): void
    {
        $doble = $this->turnoConCliente('Ana Maria', 'Perez');
        $this->turnoConCliente('Ana', 'Perez');

        $this->assertSame([$doble], $this->ids($this->listar(['buscar' => 'ana mar'])->assertOk()));
    }

    public function test_buscar_matches_a_client_without_apellido_by_nombre(): void
    {
        $sinApellido = $this->turnoConCliente('Lucia', null);
        $this->turnoConCliente('Marta', 'Gomez');

        $this->assertSame([$sinApellido], $this->ids($this->listar(['buscar' => 'luc'])->assertOk()));
    }

    public function test_buscar_is_case_insensitive_on_both_sides_for_ascii(): void
    {
        $mayusculas = $this->turnoConCliente('MARIA', 'LOPEZ');
        $minusculas = $this->turnoConCliente('maria', 'lopez');
        $this->turnoConCliente('Ana', 'Gomez');

        $this->assertSame([$mayusculas, $minusculas], $this->ids($this->listar(['buscar' => 'MaRiA LoPeZ'])->assertOk()));
    }

    public function test_buscar_lowercases_non_ascii_letters_of_the_term_in_php(): void
    {
        // sqlite LOWER() only folds ASCII, so the stored value is already lowercase; the
        // term "ÑANDU" must be lowered on our side. Folding a stored UPPERCASE Ñ is
        // verifiable only on Postgres, where LOWER() is locale aware.
        $nandu = $this->turnoConCliente('ñandu', 'Gomez');
        $this->turnoConCliente('Nora', 'Gomez');

        $this->assertSame([$nandu], $this->ids($this->listar(['buscar' => 'ÑANDU'])->assertOk()));
    }

    public function test_buscar_collapses_whitespace_between_words(): void
    {
        $maria = $this->turnoConCliente('Maria', 'Lopez');
        $this->turnoConCliente('Mariana', 'Perez');

        $this->assertSame([$maria], $this->ids($this->listar(['buscar' => "  mar \t  lop  "])->assertOk()));
    }

    public function test_like_metacharacters_in_buscar_are_literal(): void
    {
        $porcentaje = $this->turnoConCliente('Descuento 50%', 'Gomez');
        $guion = $this->turnoConCliente('Ana_Maria', 'Gomez');
        $exclamacion = $this->turnoConCliente('Hola!', 'Gomez');
        $comun = $this->turnoConCliente('Ana Maria', 'Gomez');

        $this->assertSame([$porcentaje], $this->ids($this->listar(['buscar' => '%'])->assertOk()));
        $this->assertSame([$guion], $this->ids($this->listar(['buscar' => '_'])->assertOk()));
        $this->assertSame([$exclamacion], $this->ids($this->listar(['buscar' => '!'])->assertOk()));
        // "_" must not act as a single-character wildcard, nor "%" as a multi-character one.
        $this->assertSame([$guion], $this->ids($this->listar(['buscar' => 'ana_mar'])->assertOk()));
        $this->assertSame([], $this->ids($this->listar(['buscar' => 'an%ia'])->assertOk()));
        // "!" is the escape character of the pattern and still an ordinary letter for the user.
        $this->assertSame([$exclamacion], $this->ids($this->listar(['buscar' => 'la!'])->assertOk()));
        $this->assertSame([], $this->ids($this->listar(['buscar' => 'an!'])->assertOk()));
        $this->assertNotContains($comun, $this->ids($this->listar(['buscar' => '_'])->assertOk()));
    }

    public function test_buscar_never_matches_the_phone_number(): void
    {
        $this->turnoConCliente('Maria', 'Lopez', [], '1155551234');
        $porNombre = $this->turnoConCliente('1155', 'Gomez', [], '1100000000');

        // Digits in the phone find nothing; the same digits in a NAME do (so the search runs).
        $this->assertSame([$porNombre], $this->ids($this->listar(['buscar' => '1155'])->assertOk()));
        $this->assertSame([], $this->ids($this->listar(['buscar' => '1234'])->assertOk()));
        $this->assertSame([], $this->ids($this->listar(['buscar' => '+54911'])->assertOk()));
    }

    public function test_buscar_never_matches_notes_or_service_names(): void
    {
        $servicio = $this->crearServicioPortal($this->negocio['user'], 'Pedicura Lopez');
        $conNota = $this->turnoConCliente('Maria', 'Gomez', ['notas' => 'Pregunta por Lopez']);
        $conServicio = $this->turnoConCliente('Ana', 'Gomez');
        Turno::find($conServicio)->servicios()->attach($servicio->id, ['precio' => 100]);
        $this->turnoConCliente('Lopez', 'Gomez');

        $respuesta = $this->listar(['buscar' => 'lopez'])->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        $this->assertNotContains($conNota, $this->ids($respuesta));
        $this->assertNotContains($conServicio, $this->ids($respuesta));
        $this->assertSame([], $this->ids($this->listar(['buscar' => 'pedicura'])->assertOk()));
    }

    public function test_buscar_excludes_turnos_without_a_client(): void
    {
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 09:00:00');
        $conCliente = $this->turnoConCliente('Maria', 'Lopez');

        $this->assertSame([$conCliente], $this->ids($this->listar(['buscar' => 'maria'])->assertOk()));
    }

    public function test_buscar_never_reaches_beyond_her_own_turnos(): void
    {
        $suyo = $this->turnoConCliente('Maria', 'Lopez');
        $clienteDeBea = $this->crearClientePortal($this->negocio['user'], 'Maria', 'Lopez', $this->telefonoUnico());
        $this->crearTurnoPortal($this->negocio['bea'], '2026-10-17 10:00:00', ['cliente_id' => $clienteDeBea->id]);
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 11:00:00', ['cliente_id' => $clienteDeBea->id, 'estado' => 'cancelado']);
        $otro = $this->crearNegocioPortal();
        $clienteAjeno = $this->crearClientePortal($otro['user'], 'Maria', 'Lopez', $this->telefonoUnico());
        $this->crearTurnoPortal($otro['ana'], '2026-10-17 10:00:00', ['cliente_id' => $clienteAjeno->id]);

        $this->assertSame([$suyo], $this->ids($this->listar(['buscar' => 'maria lopez'])->assertOk()));
        // A name that exists only on turnos she cannot see answers exactly like a name that exists nowhere.
        $this->assertSame([], $this->ids($this->listar(['buscar' => 'nadie'])->assertOk()));
    }

    public function test_buscar_combines_with_the_date_range(): void
    {
        $cliente = $this->crearClientePortal($this->negocio['user'], 'Maria', 'Lopez', $this->telefonoUnico());
        $dentro = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-11 10:00:00', ['cliente_id' => $cliente->id]);
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-20 10:00:00', ['cliente_id' => $cliente->id]);
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-11 12:00:00');

        $respuesta = $this->listar(['buscar' => 'maria', 'desde' => '2026-10-10', 'hasta' => '2026-10-12'])->assertOk();

        $this->assertSame([$dentro->id], $this->ids($respuesta));
    }

    public function test_a_blank_buscar_is_no_filter(): void
    {
        $uno = $this->turnoConCliente('Maria', 'Lopez');
        $dos = $this->turnoConCliente('Ana', 'Gomez');
        $sinCliente = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 11:00:00')->id;

        $esperado = [$uno, $dos, $sinCliente];
        $this->assertSame($esperado, $this->ids($this->listar(['buscar' => '   '])->assertOk()));
        $this->assertSame($esperado, $this->ids($this->listar(['buscar' => ''])->assertOk()));
    }

    public function test_buscar_accepts_60_characters_and_5_words(): void
    {
        $this->turnoConCliente('Maria', 'Lopez');

        $sesenta = str_repeat('a', 60);
        $this->assertSame([], $this->ids($this->listar(['buscar' => $sesenta])->assertOk()));
        $this->assertSame([], $this->ids($this->listar(['buscar' => 'a b c d e'])->assertOk()));
    }

    #[DataProvider('buscarInvalidos')]
    public function test_an_oversized_or_malformed_buscar_is_a_422(mixed $buscar): void
    {
        $this->turnoConCliente('Maria', 'Lopez');

        $this->listar(['buscar' => $buscar])->assertStatus(422)->assertJsonValidationErrors('buscar');
    }

    /** @return array<string, array{0: mixed}> */
    public static function buscarInvalidos(): array
    {
        return [
            '61 characters' => [str_repeat('a', 61)],
            '6 words' => ['a b c d e f'],
            '6 words with extra spaces' => ['a  b   c d e   f'],
            '61 multibyte characters' => [str_repeat('ñ', 61)],
            'an array' => [['maria']],
        ];
    }

    public function test_buscar_uses_an_escaped_lower_like_and_never_ilike_or_the_phone_column(): void
    {
        $this->turnoConCliente('Maria', 'Lopez');

        DB::enableQueryLog();
        $this->listar(['buscar' => 'mar lop'])->assertOk();
        $consultas = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $q) => str_contains($q, '"clientes"'))
            ->implode("\n");

        $this->assertStringContainsStringIgnoringCase("escape '!'", $consultas);
        $this->assertStringContainsString('LOWER(', $consultas);
        $this->assertStringNotContainsStringIgnoringCase('ilike', $consultas);
        $this->assertStringNotContainsString('telefono', $consultas);
        $this->assertStringNotContainsString('notas', $consultas);
    }

    // ── service filter: servicio_id (AG-04) ──────────────────────

    /** A service of the negocio assigned to the professional. */
    private function servicioDe(\App\Models\Profesional $profesional, string $nombre, array $attrs = []): \App\Models\Servicio
    {
        $servicio = $this->crearServicioPortal($profesional->user, $nombre, $attrs);
        $profesional->servicios()->attach($servicio->id);

        return $servicio;
    }

    /** @param list<\App\Models\Servicio> $servicios */
    private function turnoConServicios(array $servicios, string $hora = '10:00:00', ?\App\Models\Profesional $profesional = null): int
    {
        $turno = $this->crearTurnoPortal($profesional ?? $this->negocio['ana'], '2026-10-17 '.$hora);
        foreach ($servicios as $servicio) {
            $turno->servicios()->attach($servicio->id, ['precio' => 100]);
        }

        return $turno->id;
    }

    public function test_servicio_id_keeps_only_her_turnos_that_include_that_service(): void
    {
        $capping = $this->servicioDe($this->negocio['ana'], 'Capping');
        $pedicura = $this->servicioDe($this->negocio['ana'], 'Pedicura');
        $soloCapping = $this->turnoConServicios([$capping], '09:00:00');
        $ambos = $this->turnoConServicios([$capping, $pedicura], '10:00:00');
        $soloPedicura = $this->turnoConServicios([$pedicura], '11:00:00');
        $this->turnoConServicios([], '12:00:00');

        $this->assertSame([$soloCapping, $ambos], $this->ids($this->listar(['servicio_id' => $capping->id])->assertOk()));
        $this->assertSame([$ambos, $soloPedicura], $this->ids($this->listar(['servicio_id' => $pedicura->id])->assertOk()));
    }

    public function test_servicio_id_does_not_bring_other_professionals_turnos_of_a_shared_service(): void
    {
        $compartido = $this->servicioDe($this->negocio['ana'], 'Capping');
        $this->negocio['bea']->servicios()->attach($compartido->id);
        $suyo = $this->turnoConServicios([$compartido], '09:00:00');
        $this->turnoConServicios([$compartido], '10:00:00', $this->negocio['bea']);

        $this->assertSame([$suyo], $this->ids($this->listar(['servicio_id' => $compartido->id])->assertOk()));
    }

    public function test_a_service_not_assigned_to_her_is_a_422_whoever_owns_it_or_whether_it_exists(): void
    {
        $this->servicioDe($this->negocio['ana'], 'Capping');
        $deBea = $this->servicioDe($this->negocio['bea'], 'Solo de Bea');
        $sinAsignar = $this->crearServicioPortal($this->negocio['user'], 'Sin asignar');
        $otro = $this->crearNegocioPortal();
        $ajeno = $this->servicioDe($otro['ana'], 'De otro negocio');
        // Same professional id/assignment trick: a service of ANOTHER negocio attached to her by mistake.
        $colado = $this->crearServicioPortal($otro['user'], 'Colado');
        $this->negocio['ana']->servicios()->attach($colado->id);

        $cuerpos = [];
        foreach ([$deBea->id, $sinAsignar->id, $ajeno->id, $colado->id, 999999] as $id) {
            $respuesta = $this->listar(['servicio_id' => $id])->assertStatus(422)->assertJsonValidationErrors('servicio_id');
            $cuerpos[$id] = $respuesta->getContent();
        }

        // One single body: not even the id is echoed, so it cannot tell "exists elsewhere" from "does not exist".
        $this->assertCount(1, array_unique($cuerpos));
        $this->assertStringNotContainsString((string) $ajeno->id.'"', (string) reset($cuerpos));
    }

    public function test_an_inactive_service_assigned_to_her_still_filters_old_turnos(): void
    {
        $viejo = $this->servicioDe($this->negocio['ana'], 'Retirado', ['activo' => false]);
        $turno = $this->turnoConServicios([$viejo]);

        $this->assertSame([$turno], $this->ids($this->listar(['servicio_id' => $viejo->id])->assertOk()));
    }

    #[DataProvider('serviciosInvalidos')]
    public function test_a_non_integer_servicio_id_is_a_422_with_the_same_message(mixed $valor): void
    {
        $this->servicioDe($this->negocio['ana'], 'Capping');
        $referencia = $this->listar(['servicio_id' => 999999])->assertStatus(422)->getContent();

        $this->assertSame($referencia, $this->listar(['servicio_id' => $valor])->assertStatus(422)->getContent());
    }

    /** @return array<string, array{0: mixed}> */
    public static function serviciosInvalidos(): array
    {
        return [
            'letters' => ['abc'],
            'decimal' => ['1.5'],
            'exponent' => ['1e0'],
            'negative' => ['-1'],
            'zero' => ['0'],
            'overflow' => ['99999999999999999999'],
            'array' => [['1']],
        ];
    }

    public function test_servicio_id_combines_with_buscar_and_the_range(): void
    {
        $capping = $this->servicioDe($this->negocio['ana'], 'Capping');
        $cliente = $this->crearClientePortal($this->negocio['user'], 'Maria', 'Lopez', $this->telefonoUnico());
        $otraClienta = $this->crearClientePortal($this->negocio['user'], 'Ana', 'Gomez', $this->telefonoUnico());
        $buscado = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-11 10:00:00', ['cliente_id' => $cliente->id]);
        $buscado->servicios()->attach($capping->id, ['precio' => 100]);
        $otroCliente = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-11 11:00:00', ['cliente_id' => $otraClienta->id]);
        $otroCliente->servicios()->attach($capping->id, ['precio' => 100]);
        $fueraDeRango = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-20 10:00:00', ['cliente_id' => $cliente->id]);
        $fueraDeRango->servicios()->attach($capping->id, ['precio' => 100]);
        $this->crearTurnoPortal($this->negocio['ana'], '2026-10-11 12:00:00', ['cliente_id' => $cliente->id]);

        $respuesta = $this->listar([
            'servicio_id' => $capping->id,
            'buscar' => 'maria',
            'desde' => '2026-10-10',
            'hasta' => '2026-10-12',
        ])->assertOk();

        $this->assertSame([$buscado->id], $this->ids($respuesta));
    }

    public function test_the_service_filter_does_not_repeat_a_turno_with_two_matching_rows(): void
    {
        $capping = $this->servicioDe($this->negocio['ana'], 'Capping');
        $turno = $this->turnoConServicios([$capping]);

        $respuesta = $this->listar(['servicio_id' => $capping->id])->assertOk();

        $this->assertSame([$turno], $this->ids($respuesta));
        $this->assertCount(1, $respuesta->json('data.0.servicios'));
    }
}

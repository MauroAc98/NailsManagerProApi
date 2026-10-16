<?php

namespace Tests\Feature\Pro;

use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * GET /api/pro/servicios (AG-09): the services she can be booked for, as
 * `[{id, nombre}]` and nothing else (no price, duration, category or pivot).
 * The professional and the negocio come from the link only.
 */
class ProServiciosTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    /** Keys that must not exist at any depth of the response. */
    private const CLAVES_PROHIBIDAS = [
        'precio', 'precio_sugerido', 'duracion_minutos', 'categoria_id', 'categoria', 'activo', 'es_promo', 'orden',
        'modo_promo', 'user_id', 'profesional_id', 'pivot', 'created_at', 'updated_at', 'fotos', 'componentes',
    ];

    private array $negocio;

    private ProfesionalCuenta $cuenta;

    private ProfesionalVinculo $vinculo;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->negocio = $this->crearNegocioPortal();
        $this->cuenta = $this->crearCuentaPortal();
        $this->vinculo = $this->vincularPortal($this->cuenta, $this->negocio['ana']);
        $this->token = $this->tokenPara($this->cuenta);
    }

    /** @param array<string, mixed> $query */
    private function servicios(array $query = [], ?int $vinculoId = null, ?string $token = null): TestResponse
    {
        // Sanctum's guard caches the first resolved user per app instance.
        $this->app['auth']->forgetGuards();
        $uri = '/api/pro/servicios'.($query === [] ? '' : '?'.http_build_query($query));

        return $this->withToken($token ?? $this->token)
            ->getJson($uri, ['X-Vinculo-Id' => (string) ($vinculoId ?? $this->vinculo->id)]);
    }

    /** A service of the titular's negocio assigned to $profesional. */
    private function asignar($profesional, string $nombre, array $attrs = [])
    {
        $servicio = $this->crearServicioPortal($this->negocio['user'], $nombre, $attrs);
        $profesional->servicios()->attach($servicio->id);

        return $servicio;
    }

    /** @return list<string> */
    private function nombres(TestResponse $respuesta): array
    {
        return array_column($respuesta->json(), 'nombre');
    }

    // ── scope: only hers ─────────────────────────────────────────

    public function test_it_lists_only_the_services_assigned_to_her(): void
    {
        $uno = $this->asignar($this->negocio['ana'], 'Capping');
        $dos = $this->asignar($this->negocio['ana'], 'Esmaltado');
        $this->asignar($this->negocio['bea'], 'Pedicura');
        $this->crearServicioPortal($this->negocio['user'], 'Sin asignar a nadie');

        $r = $this->servicios()->assertOk();

        $this->assertSame([
            ['id' => $uno->id, 'nombre' => 'Capping'],
            ['id' => $dos->id, 'nombre' => 'Esmaltado'],
        ], $r->json());
    }

    public function test_another_professional_of_the_same_negocio_sees_her_own_services(): void
    {
        $this->asignar($this->negocio['ana'], 'Capping');
        $deBea = $this->asignar($this->negocio['bea'], 'Pedicura');
        $vinculoBea = $this->vincularPortal($this->crearCuentaPortal('bea@x.com'), $this->negocio['bea']);

        $r = $this->servicios([], $vinculoBea->id, $this->tokenPara($vinculoBea->cuenta))->assertOk();

        $this->assertSame([['id' => $deBea->id, 'nombre' => 'Pedicura']], $r->json());
    }

    public function test_services_of_another_negocio_are_never_listed_even_if_attached_to_her(): void
    {
        $suyo = $this->asignar($this->negocio['ana'], 'Capping');
        $otroNegocio = $this->crearNegocioPortal();
        $ajeno = $this->crearServicioPortal($otroNegocio['user'], 'ServicioDeOtroNegocio');
        $this->negocio['ana']->servicios()->attach($ajeno->id);

        $r = $this->servicios()->assertOk();

        $this->assertSame([['id' => $suyo->id, 'nombre' => 'Capping']], $r->json());
        $this->assertStringNotContainsString('ServicioDeOtroNegocio', $r->getContent());
    }

    public function test_inactive_services_and_promos_are_not_listed(): void
    {
        $activo = $this->asignar($this->negocio['ana'], 'Capping');
        $this->asignar($this->negocio['ana'], 'Servicio apagado', ['activo' => false]);
        $this->asignar($this->negocio['ana'], 'Promo manicura y pedicura', ['es_promo' => true]);

        $this->assertSame(
            [['id' => $activo->id, 'nombre' => 'Capping']],
            $this->servicios()->assertOk()->json(),
        );
    }

    public function test_professional_and_user_ids_sent_in_the_query_are_ignored(): void
    {
        $suyo = $this->asignar($this->negocio['ana'], 'Capping');
        $this->asignar($this->negocio['bea'], 'Pedicura');
        $otroNegocio = $this->crearNegocioPortal();
        $this->crearServicioPortal($otroNegocio['user'], 'ServicioDeOtroNegocio');

        $r = $this->servicios([
            'profesional_id' => $this->negocio['bea']->id,
            'user_id' => $otroNegocio['user']->id,
            'negocio_id' => $otroNegocio['user']->id,
        ])->assertOk();

        $this->assertSame([['id' => $suyo->id, 'nombre' => 'Capping']], $r->json());
    }

    public function test_a_professional_without_services_gets_an_empty_json_list(): void
    {
        // The negocio has services, but none of them is assigned to her.
        $this->asignar($this->negocio['bea'], 'Pedicura');

        $this->assertSame('[]', $this->servicios()->assertOk()->getContent());
    }

    // ── order ────────────────────────────────────────────────────

    public function test_services_come_ordered_by_orden_then_nombre_then_id(): void
    {
        $zetaPrimero = $this->asignar($this->negocio['ana'], 'Zeta', ['orden' => 0]);
        $alfaUno = $this->asignar($this->negocio['ana'], 'Alfa', ['orden' => 5]);
        $alfaDos = $this->asignar($this->negocio['ana'], 'Alfa', ['orden' => 5]);
        $beta = $this->asignar($this->negocio['ana'], 'Beta', ['orden' => 5]);
        $ultimo = $this->asignar($this->negocio['ana'], 'Aaa', ['orden' => 9]);

        $r = $this->servicios()->assertOk();

        // orden decides first (Zeta 0 before Alfa 5), nombre breaks ties inside an
        // orden, and the id breaks the tie between the two "Alfa".
        $this->assertSame(
            [$zetaPrimero->id, $alfaUno->id, $alfaDos->id, $beta->id, $ultimo->id],
            array_column($r->json(), 'id'),
        );
    }

    public function test_the_query_carries_an_explicit_deterministic_order_by(): void
    {
        $this->asignar($this->negocio['ana'], 'Capping');
        $this->servicios()->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->servicios()->assertOk();

        $consulta = collect(DB::getQueryLog())
            ->pluck('query')
            ->first(fn ($q) => str_contains($q, 'from "servicios"'));
        $this->assertNotNull($consulta);
        // SQLite returns rows in id order by accident; Postgres guarantees nothing without ORDER BY.
        $this->assertStringContainsString(
            'order by "servicios"."orden" asc, "servicios"."nombre" asc, "servicios"."id" asc',
            strtolower($consulta),
        );
    }

    // ── what it exposes ──────────────────────────────────────────

    public function test_each_item_has_exactly_id_and_nombre(): void
    {
        $this->asignar($this->negocio['ana'], 'Capping');
        $this->asignar($this->negocio['ana'], 'Esmaltado');

        $items = $this->servicios()->assertOk()->json();

        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertEqualsCanonicalizing(['id', 'nombre'], array_keys($item));
            $this->assertIsInt($item['id']);
            $this->assertIsString($item['nombre']);
        }
    }

    public function test_no_price_duration_category_or_flag_leaks(): void
    {
        $categoria = DB::table('categorias_servicio')->insertGetId([
            'user_id' => $this->negocio['user']->id,
            'nombre' => 'CategoriaCanario',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->asignar($this->negocio['ana'], 'Capping', [
            'precio' => 54321,
            'duracion_minutos' => 173,
            'orden' => 7,
            'categoria_id' => $categoria,
        ]);
        $this->asignar($this->negocio['bea'], 'ServicioDeBea', ['precio' => 98765]);

        $r = $this->servicios()->assertOk();
        $contenido = $r->getContent();

        foreach (['54321', '173', 'CategoriaCanario', '98765', 'ServicioDeBea', 'Bea'] as $canario) {
            $this->assertStringNotContainsString($canario, $contenido);
        }
        $this->assertSame('[{"id":'.$r->json('0.id').',"nombre":"Capping"}]', $contenido);
        $cuerpo = $r->json();
        array_walk_recursive($cuerpo, function ($valor, $clave) {
            $this->assertNotContains($clave, self::CLAVES_PROHIBIDAS);
        });
        $this->assertSame(['id', 'nombre'], array_keys($r->json('0')));
    }

    public function test_the_response_is_not_cacheable(): void
    {
        $this->assertStringContainsString('no-store', $this->servicios()->assertOk()->headers->get('Cache-Control'));
    }

    // ── link, token and middleware stack ─────────────────────────

    public function test_without_the_link_header_it_is_422_vinculo_requerido(): void
    {
        $this->app['auth']->forgetGuards();

        $this->withToken($this->token)
            ->getJson('/api/pro/servicios')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VINCULO_REQUERIDO');
    }

    public function test_the_link_of_another_account_is_403_with_the_same_body_as_a_nonexistent_link(): void
    {
        $ajeno = $this->vincularPortal($this->crearCuentaPortal('otra@x.com'), $this->negocio['bea']);

        $ajena = $this->servicios([], $ajeno->id)->assertForbidden();
        $inexistente = $this->servicios([], 999999)->assertForbidden();

        $this->assertSame($inexistente->getContent(), $ajena->getContent());
        $this->assertSame('VINCULO_NO_DISPONIBLE', $ajena->json('code'));
    }

    public function test_a_negocio_with_a_lapsed_subscription_gets_the_tenant_code(): void
    {
        $sinSuscripcion = $this->crearNegocioPortal(['is_exempt' => false]);
        $vinculo = $this->vincularPortal($this->crearCuentaPortal('sinsub@x.com'), $sinSuscripcion['ana']);

        $this->servicios([], $vinculo->id, $this->tokenPara($vinculo->cuenta))
            ->assertForbidden()
            ->assertJsonPath('code', 'NO_SUBSCRIPTION');
    }

    public function test_it_is_not_reachable_without_a_token_and_never_with_a_write_method(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/pro/servicios')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)
            ->postJson('/api/pro/servicios', ['nombre' => 'Nuevo'], ['X-Vinculo-Id' => (string) $this->vinculo->id])
            ->assertStatus(405);
    }

    // ── cost ─────────────────────────────────────────────────────

    public function test_the_query_count_does_not_grow_with_the_number_of_services(): void
    {
        $this->asignar($this->negocio['ana'], 'Servicio 0');
        [$consultasCon1, $itemsCon1] = $this->contarConsultas();

        for ($i = 1; $i < 30; $i++) {
            $this->asignar($this->negocio['ana'], 'Servicio '.$i);
        }
        [$consultasCon30, $itemsCon30] = $this->contarConsultas();

        $this->assertSame(1, $itemsCon1);
        $this->assertSame(30, $itemsCon30);
        $this->assertSame($consultasCon1, $consultasCon30);
    }

    /** @return array{0: int, 1: int} queries issued by one request, items returned */
    private function contarConsultas(): array
    {
        // Warm-up: Sanctum writes last_used_at only on the first use of a token,
        // which would make the first measurement one query heavier.
        $this->servicios()->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $respuesta = $this->servicios()->assertOk();

        return [count(DB::getQueryLog()), count($respuesta->json())];
    }
}

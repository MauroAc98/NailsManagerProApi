<?php

namespace Tests\Feature\Pro;

use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * pro.vigente: the per-request validity check. Cuenta layer here (account
 * switch-off, rate limit, no-store); the link layer is appended below.
 */
class ProVigenteTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private array $negocio;

    private ProfesionalCuenta $cuenta;

    private ProfesionalVinculo $vinculo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->negocio = $this->crearNegocioPortal();
        $this->cuenta = $this->crearCuentaPortal();
        $this->vinculo = $this->vincularPortal($this->cuenta, $this->negocio['ana']);

        // Test-only probe with the production stack of a link-scoped route
        // (no such production route exists yet). It echoes what the middleware
        // derived, never what the request sent.
        Route::prefix('api/pro')
            ->middleware(['portal.pro', 'auth:profesional', 'abilities:pro:acceso', 'pro.vigente'])
            ->get('sonda-vinculo', fn (Request $r) => response()->json([
                'vinculo_id' => $r->attributes->get('pro.vinculo')->id,
                'profesional_id' => $r->attributes->get('pro.vinculo')->profesional_id,
            ]));
    }

    private function llamar(string $metodo, string $uri, string $token, array $headers = [])
    {
        // Sanctum's guard caches the first resolved user per app instance.
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($metodo, $uri, [], $headers);
    }

    // ── cuenta layer ─────────────────────────────────────────────

    public function test_inactive_account_loses_every_token_and_gets_a_generic_401_on_the_next_request(): void
    {
        $dispositivo1 = $this->tokenPara($this->cuenta);
        $dispositivo2 = $this->tokenPara($this->cuenta);
        $this->llamar('GET', '/api/pro/me', $dispositivo1)->assertOk();

        $this->cuenta->forceFill(['activo' => false])->save();

        $this->llamar('GET', '/api/pro/me', $dispositivo1)
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertSame(0, PersonalAccessToken::count());
        // The other device is cut too: its token no longer exists.
        $this->llamar('GET', '/api/pro/me', $dispositivo2)->assertStatus(401);
    }

    public function test_inactive_account_is_also_cut_off_on_logout(): void
    {
        $token = $this->tokenPara($this->cuenta);
        $otro = $this->tokenPara($this->cuenta);
        $this->cuenta->forceFill(['activo' => false])->save();

        $this->llamar('POST', '/api/pro/logout', $token)->assertStatus(401);
        $this->assertSame(0, PersonalAccessToken::where('id', explode('|', $otro)[0])->count());
    }

    public function test_reactivating_the_account_does_not_bring_revoked_tokens_back(): void
    {
        $token = $this->tokenPara($this->cuenta);
        $this->cuenta->forceFill(['activo' => false])->save();
        $this->llamar('GET', '/api/pro/me', $token)->assertStatus(401);

        $this->cuenta->forceFill(['activo' => true])->save();

        $this->llamar('GET', '/api/pro/me', $token)->assertStatus(401);
    }

    public function test_the_authenticated_rate_limit_is_60_per_minute_per_account(): void
    {
        $token = $this->tokenPara($this->cuenta);
        for ($i = 0; $i < 60; $i++) {
            $this->llamar('GET', '/api/pro/me', $token)->assertOk();
        }

        $this->llamar('GET', '/api/pro/me', $token)->assertStatus(429);

        $otraCuenta = $this->crearCuentaPortal('otra@x.com');
        $this->vincularPortal($otraCuenta, $this->negocio['bea']);
        $this->llamar('GET', '/api/pro/me', $this->tokenPara($otraCuenta))->assertOk();
    }

    public function test_authenticated_and_login_responses_are_not_cacheable(): void
    {
        $token = $this->tokenPara($this->cuenta);

        $this->assertStringContainsString('no-store', $this->llamar('GET', '/api/pro/me', $token)->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $this->llamar('GET', '/api/pro/me', 'basura')->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $this->postJson('/api/pro/login', [
            'email' => 'ana@x.com',
            'password' => 'password-seguro',
        ])->headers->get('Cache-Control'));
    }

    // ── link layer (probe route) ─────────────────────────────────

    private function sonda(string $token, ?string $vinculoId, string $query = '')
    {
        return $this->llamar('GET', '/api/pro/sonda-vinculo' . $query, $token, $vinculoId === null ? [] : ['X-Vinculo-Id' => $vinculoId]);
    }

    /** A second negocio (not exempt) where this account also has a link. */
    private function vincularNegocioDePago(?string $status, int $dias = 10): ProfesionalVinculo
    {
        $negocio = $this->crearNegocioPortal(['is_exempt' => false]);
        if ($status !== null) {
            Subscription::create(['user_id' => $negocio['user']->id, 'ends_at' => now()->addDays($dias), 'status' => $status]);
        }

        return $this->vincularPortal($this->cuenta, $negocio['ana']);
    }

    private function assertNoDisponible($respuesta): void
    {
        $respuesta->assertStatus(403)->assertJsonPath('code', 'VINCULO_NO_DISPONIBLE');
    }

    public function test_an_owned_active_link_resolves_and_exposes_the_professional_derived_from_it(): void
    {
        $this->sonda($this->tokenPara($this->cuenta), (string) $this->vinculo->id)
            ->assertOk()
            ->assertExactJson(['vinculo_id' => $this->vinculo->id, 'profesional_id' => $this->negocio['ana']->id]);
    }

    public function test_a_missing_header_is_422_vinculo_requerido(): void
    {
        $this->sonda($this->tokenPara($this->cuenta), null)
            ->assertStatus(422)
            ->assertJsonPath('code', 'VINCULO_REQUERIDO');
    }

    #[DataProvider('encabezadosMalformados')]
    public function test_a_malformed_header_is_422_vinculo_requerido(string $valor): void
    {
        $this->sonda($this->tokenPara($this->cuenta), $valor)
            ->assertStatus(422)
            ->assertJsonPath('code', 'VINCULO_REQUERIDO');
    }

    public static function encabezadosMalformados(): array
    {
        return [
            'empty' => [''], 'letters' => ['abc'], 'zero' => ['0'], 'leading zeros' => ['007'],
            'negative' => ['-1'], 'decimal' => ['1.5'], 'sql' => ['1 OR 1=1'], 'overflow' => ['99999999999999999999'],
            'trailing newline' => ["1\n"],
        ];
    }

    public function test_the_rate_limiter_runs_before_pro_vigente_so_rejected_requests_are_counted(): void
    {
        foreach (['GET' => 'api/pro/me', 'POST' => 'api/pro/logout'] as $metodo => $uri) {
            $ruta = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/' . $uri, $metodo));
            $middleware = array_values(array_filter(
                $ruta->gatherMiddleware(),
                fn ($m) => is_string($m) && (str_starts_with($m, 'throttle:pro') || str_starts_with($m, 'pro.vigente')),
            ));

            $this->assertSame(['throttle:pro'], array_slice($middleware, 0, 1), $uri . ' must throttle before pro.vigente');
        }
    }

    public function test_foreign_nonexistent_inactive_link_and_inactive_professional_are_one_identical_403(): void
    {
        $token = $this->tokenPara($this->cuenta);
        $ajeno = $this->vincularPortal($this->crearCuentaPortal('otra@x.com'), $this->negocio['bea']);
        $inactivo = $this->vincularPortal($this->cuenta, $this->negocio['titular'], false);
        $profesionalInactivo = $this->crearProfesionalPortal($this->negocio['user'], 'Cami', false);
        $conProfesionalInactivo = $this->vincularPortal($this->cuenta, $profesionalInactivo);

        $cuerpos = [];
        foreach ([$ajeno->id, 987654, $inactivo->id, $conProfesionalInactivo->id] as $id) {
            $r = $this->sonda($token, (string) $id);
            $this->assertNoDisponible($r);
            $cuerpos[] = $r->getContent();
        }

        $this->assertCount(1, array_unique($cuerpos), 'every cause must produce a byte-identical body');
        $this->sonda($token, (string) $this->vinculo->id)->assertOk();
    }

    public function test_a_removed_link_is_cut_off_on_the_next_request(): void
    {
        $token = $this->tokenPara($this->cuenta);
        $this->sonda($token, (string) $this->vinculo->id)->assertOk();

        $this->vinculo->delete();

        $this->assertNoDisponible($this->sonda($token, (string) $this->vinculo->id));
    }

    public function test_toggling_the_link_or_the_professional_cuts_and_restores_access_immediately(): void
    {
        $token = $this->tokenPara($this->cuenta);
        $id = (string) $this->vinculo->id;

        $this->vinculo->forceFill(['activo' => false])->save();
        $this->assertNoDisponible($this->sonda($token, $id));
        $this->vinculo->forceFill(['activo' => true])->save();
        $this->sonda($token, $id)->assertOk();

        $this->negocio['ana']->forceFill(['activo' => false])->save();
        $this->assertNoDisponible($this->sonda($token, $id));
        $this->negocio['ana']->forceFill(['activo' => true])->save();
        $this->sonda($token, $id)->assertOk();
    }

    public function test_a_colleague_link_of_the_same_negocio_is_not_usable(): void
    {
        $colega = $this->vincularPortal($this->crearCuentaPortal('bea@x.com'), $this->negocio['bea']);

        $this->assertNoDisponible($this->sonda($this->tokenPara($this->cuenta), (string) $colega->id));
    }

    // ── order of checks ──────────────────────────────────────────

    public function test_order_inactive_account_wins_over_a_malformed_header(): void
    {
        $token = $this->tokenPara($this->cuenta);
        $this->cuenta->forceFill(['activo' => false])->save();

        $this->sonda($token, 'abc')->assertStatus(401);
    }

    public function test_order_ability_wins_over_everything_for_a_restricted_token(): void
    {
        $this->sonda($this->tokenPara($this->cuenta, ['pro:cambiar-password']), null)->assertStatus(403)->assertJsonMissingPath('code');
    }

    public function test_order_password_change_wins_over_the_header_and_blocks_a_full_token(): void
    {
        $this->cuenta->forceFill(['debe_cambiar_password' => true])->save();
        $token = $this->tokenPara($this->cuenta);

        $this->sonda($token, null)->assertStatus(403)->assertJsonPath('code', 'PASSWORD_CAMBIO_REQUERIDO');
        $this->sonda($token, (string) $this->vinculo->id)->assertStatus(403)->assertJsonPath('code', 'PASSWORD_CAMBIO_REQUERIDO');
    }

    public function test_order_ownership_and_link_state_win_over_the_subscription_and_never_leak_it(): void
    {
        $token = $this->tokenPara($this->cuenta);
        $suspendido = $this->crearNegocioPortal(['is_exempt' => false]);
        Subscription::create(['user_id' => $suspendido['user']->id, 'ends_at' => now()->addDays(5), 'status' => 'SUSPENDIDO']);

        // Someone else's link in a suspended negocio: nothing about the negocio leaks.
        $ajeno = $this->vincularPortal($this->crearCuentaPortal('otra@x.com'), $suspendido['ana']);
        $this->assertNoDisponible($this->sonda($token, (string) $ajeno->id));

        // Her own but inactive link, then her own link with an inactive professional.
        $propio = $this->vincularPortal($this->cuenta, $suspendido['bea'], false);
        $this->assertNoDisponible($this->sonda($token, (string) $propio->id));
        $propio->forceFill(['activo' => true])->save();
        $suspendido['bea']->forceFill(['activo' => false])->save();
        $this->assertNoDisponible($this->sonda($token, (string) $propio->id));
    }

    // ── subscription of the link's negocio ───────────────────────

    #[DataProvider('suscripcionesQueCortan')]
    public function test_a_lapsed_subscription_blocks_with_the_tenant_code_and_body(?string $status, int $dias, string $mensaje, string $code): void
    {
        $vinculo = $this->vincularNegocioDePago($status, $dias);

        $this->sonda($this->tokenPara($this->cuenta), (string) $vinculo->id)
            ->assertStatus(403)
            ->assertExactJson(['error' => $mensaje, 'code' => $code]);
    }

    public static function suscripcionesQueCortan(): array
    {
        return [
            'no row' => [null, 0, 'Suscripción vencida', 'NO_SUBSCRIPTION'],
            'suspended with days left' => ['SUSPENDIDO', 10, 'Suscripción suspendida', 'SUBSCRIPTION_SUSPENDED'],
            'expired' => ['ACTIVO', -1, 'Suscripción vencida', 'SUBSCRIPTION_EXPIRED'],
        ];
    }

    public function test_a_valid_paid_subscription_and_an_exempt_negocio_are_allowed(): void
    {
        $pago = $this->vincularNegocioDePago('ACTIVO', 10);
        $token = $this->tokenPara($this->cuenta);

        $this->sonda($token, (string) $pago->id)->assertOk();
        $this->sonda($token, (string) $this->vinculo->id)->assertOk();
    }

    public function test_suspending_the_negocio_applies_on_the_very_next_request_and_lifting_it_restores_access(): void
    {
        $vinculo = $this->vincularNegocioDePago('ACTIVO', 10);
        $token = $this->tokenPara($this->cuenta);
        $this->sonda($token, (string) $vinculo->id)->assertOk();

        $suscripcion = Subscription::where('user_id', $vinculo->profesional->user_id)->firstOrFail();
        $suscripcion->forceFill(['status' => 'SUSPENDIDO'])->save();
        $this->sonda($token, (string) $vinculo->id)->assertStatus(403)->assertJsonPath('code', 'SUBSCRIPTION_SUSPENDED');

        $suscripcion->forceFill(['status' => 'ACTIVO'])->save();
        $this->sonda($token, (string) $vinculo->id)->assertOk();
    }

    public function test_one_token_switches_between_negocios_by_header_and_a_lapsed_one_does_not_affect_the_other(): void
    {
        $suspendido = $this->vincularNegocioDePago('SUSPENDIDO');
        $vigente = $this->vincularNegocioDePago('ACTIVO');
        $token = $this->tokenPara($this->cuenta);

        $this->sonda($token, (string) $this->vinculo->id)->assertOk()->assertJsonPath('profesional_id', $this->negocio['ana']->id);
        $this->sonda($token, (string) $suspendido->id)->assertStatus(403)->assertJsonPath('code', 'SUBSCRIPTION_SUSPENDED');
        $this->sonda($token, (string) $vigente->id)->assertOk()->assertJsonPath('profesional_id', $vigente->profesional_id);
    }

    public function test_the_professional_is_never_taken_from_the_request(): void
    {
        $r = $this->sonda(
            $this->tokenPara($this->cuenta),
            (string) $this->vinculo->id,
            '?profesional_id=' . $this->negocio['bea']->id . '&user_id=999&negocio_id=999',
        );

        $r->assertOk()->assertJsonPath('profesional_id', $this->negocio['ana']->id);
    }
}

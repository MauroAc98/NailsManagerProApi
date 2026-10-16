<?php

namespace Tests\Feature\Pro;

use App\Models\ProfesionalCuenta;
use App\Services\Portal\EmisorTokenProfesional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * PA-06 S2-S8 / D7: the forced password change. An account created with a
 * provisional password only ever gets a restricted token (15 minutes, ability
 * `pro:cambiar-password`); this endpoint is the one place that token works.
 * Success replaces every token of the account with one fresh full token.
 */
class ProCambioPasswordForzadoTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private const PROVISORIA = 'provisoria-123';
    private const NUEVA = 'contrasena-nueva-1';

    private ProfesionalCuenta $cuenta;

    protected function setUp(): void
    {
        parent::setUp();
        $negocio = $this->crearNegocioPortal();
        $this->cuenta = $this->crearCuentaPortal('ana@x.com', self::PROVISORIA, ['debe_cambiar_password' => true]);
        $this->vincularPortal($this->cuenta, $negocio['ana']);
    }

    private function llamar(string $metodo, string $uri, string $token, array $datos = [])
    {
        // Sanctum's guard caches the first resolved user per app instance.
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($metodo, $uri, $datos);
    }

    /** A real restricted token, the way login issues it. */
    private function restringido(): string
    {
        return app(EmisorTokenProfesional::class)->restringido($this->cuenta)['token'];
    }

    /** @return array<string, string> */
    private function cuerpoValido(string $password = self::NUEVA): array
    {
        return ['password' => $password, 'password_confirmation' => $password];
    }

    private function cambiar(string $token, ?array $datos = null)
    {
        return $this->llamar('POST', '/api/pro/cambiar-password', $token, $datos ?? $this->cuerpoValido());
    }

    // ── the restricted token reaches this endpoint and nothing else ──

    public function test_the_restricted_token_changes_the_password_but_is_forbidden_everywhere_else(): void
    {
        $token = $this->restringido();

        $this->llamar('GET', '/api/pro/me', $token)->assertForbidden();
        $this->cambiar($token)->assertOk();
    }

    public function test_a_full_token_cannot_call_it(): void
    {
        $completo = $this->tokenPara($this->cuenta, ['pro:acceso']);
        $this->cuenta->forceFill(['debe_cambiar_password' => false])->save();

        $this->cambiar($completo)->assertForbidden();

        $this->assertTrue(Hash::check(self::PROVISORIA, $this->cuenta->fresh()->password));
        $this->assertSame(1, PersonalAccessToken::count());
    }

    // ── success ──────────────────────────────────────────────────

    public function test_success_returns_the_login_payload_with_a_full_token_that_works(): void
    {
        $this->travelTo(now()->startOfSecond());
        $respuesta = $this->cambiar($this->restringido())
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'debe_cambiar_password'])
            ->assertJsonPath('debe_cambiar_password', false);

        $this->assertEqualsCanonicalizing(['token', 'expires_at', 'debe_cambiar_password'], array_keys($respuesta->json()));
        $this->assertSame(
            now()->addDays(EmisorTokenProfesional::DIAS_VIGENCIA)->getTimestamp(),
            \Illuminate\Support\Carbon::parse($respuesta->json('expires_at'))->getTimestamp(),
        );

        // The token is a FULL one: it opens /me and its ability is pro:acceso.
        $nuevo = $respuesta->json('token');
        $this->llamar('GET', '/api/pro/me', $nuevo)->assertOk()->assertJsonPath('cuenta.email', 'ana@x.com');
        $this->assertSame(['pro:acceso'], PersonalAccessToken::findToken($nuevo)->abilities);
    }

    public function test_success_clears_the_flag_and_changes_the_password(): void
    {
        $this->cambiar($this->restringido())->assertOk();

        $cuenta = $this->cuenta->fresh();
        $this->assertFalse($cuenta->debe_cambiar_password);
        $this->assertTrue(Hash::check(self::NUEVA, $cuenta->password));
        $this->assertFalse(Hash::check(self::PROVISORIA, $cuenta->password));
        // Stored hashed, never in clear.
        $this->assertNotSame(self::NUEVA, $cuenta->password);
    }

    public function test_the_old_password_stops_logging_in_and_the_new_one_logs_in_with_a_full_token(): void
    {
        $this->cambiar($this->restringido())->assertOk();

        $this->postJson('/api/pro/login', ['email' => 'ana@x.com', 'password' => self::PROVISORIA])
            ->assertUnauthorized();

        $login = $this->postJson('/api/pro/login', ['email' => 'ana@x.com', 'password' => self::NUEVA])
            ->assertOk()
            ->assertJsonPath('debe_cambiar_password', false);
        $this->app['auth']->forgetGuards();
        $this->llamar('GET', '/api/pro/me', $login->json('token'))->assertOk();
    }

    public function test_the_restricted_token_and_every_other_token_of_the_account_are_revoked(): void
    {
        $restringido = $this->restringido();
        $otroCompleto1 = $this->tokenPara($this->cuenta);
        $otroCompleto2 = $this->tokenPara($this->cuenta);

        $nuevo = $this->cambiar($restringido)->assertOk()->json('token');

        $this->assertSame(1, $this->cuenta->tokens()->count());
        $this->llamar('GET', '/api/pro/me', $otroCompleto1)->assertUnauthorized();
        $this->llamar('GET', '/api/pro/me', $otroCompleto2)->assertUnauthorized();
        // The restricted token is dead: it cannot change the password a second time.
        $this->cambiar($restringido, $this->cuerpoValido('otra-contrasena-2'))->assertUnauthorized();
        $this->llamar('GET', '/api/pro/me', $nuevo)->assertOk();
        $this->assertTrue(Hash::check(self::NUEVA, $this->cuenta->fresh()->password));
    }

    public function test_only_the_tokens_of_this_account_are_revoked(): void
    {
        $otra = $this->crearCuentaPortal('otra@x.com');
        $this->vincularPortal($otra, $this->crearProfesionalPortal($this->cuenta->vinculos()->first()->profesional->user, 'Cami'));
        $tokenOtra = $this->tokenPara($otra);

        $this->cambiar($this->restringido())->assertOk();

        $this->llamar('GET', '/api/pro/me', $tokenOtra)->assertOk();
    }

    // ── validation: nothing changes and the token survives ───────

    public function test_a_nine_character_password_is_422_and_the_token_stays_usable(): void
    {
        $token = $this->restringido();

        $this->cambiar($token, $this->cuerpoValido('123456789'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertTrue($this->cuenta->fresh()->debe_cambiar_password);
        $this->assertTrue(Hash::check(self::PROVISORIA, $this->cuenta->fresh()->password));
        // Same token, now with a valid password: still accepted (S3).
        $this->cambiar($token)->assertOk();
    }

    public function test_a_ten_character_password_is_accepted(): void
    {
        $this->cambiar($this->restringido(), $this->cuerpoValido('1234567890'))->assertOk();
    }

    public function test_the_provisional_password_cannot_be_reused(): void
    {
        $token = $this->restringido();

        $this->cambiar($token, $this->cuerpoValido(self::PROVISORIA))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertTrue($this->cuenta->fresh()->debe_cambiar_password);
        $this->assertSame(1, $this->cuenta->tokens()->count());
        $this->cambiar($token)->assertOk();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function cuerposInvalidos(): array
    {
        return [
            'missing password' => [['password_confirmation' => 'contrasena-nueva-1']],
            'missing confirmation' => [['password' => 'contrasena-nueva-1']],
            'mismatched confirmation' => [['password' => 'contrasena-nueva-1', 'password_confirmation' => 'contrasena-nueva-2']],
            'array password' => [['password' => ['contrasena-nueva-1'], 'password_confirmation' => ['contrasena-nueva-1']]],
            'too long' => [['password' => str_repeat('a', 256), 'password_confirmation' => str_repeat('a', 256)]],
            'empty body' => [[]],
        ];
    }

    #[DataProvider('cuerposInvalidos')]
    public function test_malformed_bodies_are_422_and_change_nothing(array $cuerpo): void
    {
        $token = $this->restringido();

        $this->cambiar($token, $cuerpo)->assertStatus(422);

        $this->assertTrue($this->cuenta->fresh()->debe_cambiar_password);
        $this->assertTrue(Hash::check(self::PROVISORIA, $this->cuenta->fresh()->password));
        $this->assertSame(1, $this->cuenta->tokens()->count());
    }

    public function test_the_validation_messages_are_in_spanish(): void
    {
        $r = $this->cambiar($this->restringido(), $this->cuerpoValido('corta'))->assertStatus(422);

        $this->assertSame('La contraseña debe tener al menos 10 caracteres.', $r->json('errors.password.0'));
    }

    // ── token lifetime and state ─────────────────────────────────

    public function test_a_restricted_token_works_inside_its_15_minutes_and_is_dead_after(): void
    {
        $this->travelTo(now()->startOfSecond());
        $viva = $this->restringido();
        $this->travel(EmisorTokenProfesional::MINUTOS_RESTRINGIDO)->minutes();
        $this->travel(-1)->seconds();

        // 14:59 after issue: still valid, and it is a 422, i.e. it got past auth.
        $this->cambiar($viva, [])->assertStatus(422);

        $this->travel(2)->seconds();
        // 15:01: expired.
        $this->cambiar($viva)->assertUnauthorized();
        $this->assertTrue(Hash::check(self::PROVISORIA, $this->cuenta->fresh()->password));
        $this->assertTrue($this->cuenta->fresh()->debe_cambiar_password);
    }

    public function test_an_inactive_account_is_cut_off_and_its_password_is_untouched(): void
    {
        $token = $this->restringido();
        $this->cuenta->forceFill(['activo' => false])->save();

        $this->cambiar($token)->assertUnauthorized();

        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertTrue(Hash::check(self::PROVISORIA, $this->cuenta->fresh()->password));
    }

    public function test_a_restricted_token_cannot_rewrite_the_password_of_an_already_activated_account(): void
    {
        // Defense in depth: the flag was cleared after the token was issued
        // (for example by another session). Voluntary changes are out of scope.
        $token = $this->restringido();
        $this->cuenta->forceFill(['debe_cambiar_password' => false])->save();

        $this->cambiar($token)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CAMBIO_NO_REQUERIDO');

        $this->assertTrue(Hash::check(self::PROVISORIA, $this->cuenta->fresh()->password));
        $this->assertSame(1, $this->cuenta->tokens()->count());
    }

    // ── no unauthenticated path ──────────────────────────────────

    public function test_there_is_no_unauthenticated_way_to_change_the_password(): void
    {
        // The tenant-style payload (email + current password), no token.
        $this->postJson('/api/pro/cambiar-password', [
            'email' => 'ana@x.com',
            'password_actual' => self::PROVISORIA,
            'password' => self::NUEVA,
            'password_confirmation' => self::NUEVA,
        ])->assertUnauthorized();

        // Same with a garbage bearer.
        $this->app['auth']->forgetGuards();
        $this->withToken('basura')->postJson('/api/pro/cambiar-password', $this->cuerpoValido())->assertUnauthorized();

        $this->assertTrue(Hash::check(self::PROVISORIA, $this->cuenta->fresh()->password));
        $this->assertTrue($this->cuenta->fresh()->debe_cambiar_password);
    }

    public function test_the_tenant_style_obligatory_change_route_does_not_exist_under_pro(): void
    {
        $cuerpo = ['email' => 'ana@x.com', 'password_actual' => self::PROVISORIA] + $this->cuerpoValido();

        $this->postJson('/api/pro/cambiar-password-obligatorio', $cuerpo)->assertNotFound();
        $this->postJson('/api/pro/auth/cambiar-password-obligatorio', $cuerpo)->assertNotFound();
        // The only route in the group with that name answers other methods with 405.
        $this->getJson('/api/pro/cambiar-password')->assertStatus(405);
        $this->assertTrue(Hash::check(self::PROVISORIA, $this->cuenta->fresh()->password));
    }

    public function test_the_route_stack_is_portal_auth_ability_throttle_then_vigente(): void
    {
        $ruta = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/pro/cambiar-password', 'POST'));

        $this->assertSame(
            ['api', 'portal.pro', 'auth:profesional', 'abilities:pro:cambiar-password', 'throttle:pro', 'pro.vigente:cuenta'],
            array_values(array_filter($ruta->gatherMiddleware(), 'is_string')),
        );
    }

    // ── hygiene ──────────────────────────────────────────────────

    public function test_the_response_is_not_cacheable_and_never_echoes_a_password(): void
    {
        $r = $this->cambiar($this->restringido())->assertOk();

        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertStringNotContainsString(self::NUEVA, $r->getContent());
        $this->assertStringNotContainsString(self::PROVISORIA, $r->getContent());
    }

    public function test_neither_password_is_ever_logged(): void
    {
        Log::spy();

        $this->cambiar($this->restringido(), $this->cuerpoValido(self::PROVISORIA))->assertStatus(422);
        $this->cambiar($this->restringido())->assertOk();

        foreach ([self::NUEVA, self::PROVISORIA] as $secreto) {
            Log::shouldNotHaveReceived('info', [\Mockery::on(fn ($m) => str_contains((string) $m, $secreto)), \Mockery::any()]);
            Log::shouldNotHaveReceived('warning', [\Mockery::on(fn ($m) => str_contains((string) $m, $secreto)), \Mockery::any()]);
        }
    }

    public function test_the_authenticated_rate_limit_applies(): void
    {
        $token = $this->restringido();
        for ($i = 0; $i < 60; $i++) {
            $this->cambiar($token, [])->assertStatus(422);
        }

        $this->cambiar($token, [])->assertStatus(429);
    }

    public function test_a_new_token_is_issued_through_the_device_cap_path(): void
    {
        // The full token comes from EmisorTokenProfesional::completo (the same
        // as login): named pro-portal, not the restricted name.
        $nuevo = $this->cambiar($this->restringido())->assertOk()->json('token');

        $this->assertSame('pro-portal', PersonalAccessToken::findToken($nuevo)->name);
    }
}

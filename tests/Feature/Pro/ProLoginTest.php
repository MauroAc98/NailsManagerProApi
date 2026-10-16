<?php

namespace Tests\Feature\Pro;

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\Pro\ProAuthController;
use App\Models\ProfesionalCuenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * POST /api/pro/login (PA-03, PA-05 login side, D11): one generic 401 for every
 * failure mode, a dummy hash check when the account does not exist, and a
 * full 30-day token that never deletes the account's other tokens.
 */
class ProLoginTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private const MENSAJE = 'Las credenciales son incorrectas.';
    private const IP = '10.7.7.7';

    /** @var array{user: User, titular: \App\Models\Profesional, ana: \App\Models\Profesional, bea: \App\Models\Profesional} */
    private array $negocio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->negocio = $this->crearNegocioPortal();
    }

    /** @param  array<string, mixed>  $extra */
    private function login(string $email, string $password, array $extra = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->postJson('/api/pro/login', ['email' => $email, 'password' => $password] + $extra);
    }

    private function cuentaVinculada(string $email = 'ana@x.com', array $attrs = [], string $quien = 'ana'): ProfesionalCuenta
    {
        $cuenta = $this->crearCuentaPortal($email, 'password-seguro', $attrs);
        $this->vincularPortal($cuenta, $this->negocio[$quien]);

        return $cuenta;
    }

    public function test_successful_login_returns_a_full_thirty_day_token(): void
    {
        Carbon::setTestNow('2026-10-17 12:00:00');
        $cuenta = $this->cuentaVinculada();

        $response = $this->login('  ANA@x.com ', 'password-seguro', ['dispositivo' => str_repeat('d', 100)]);

        $response->assertOk()->assertExactJson([
            'token' => $response->json('token'),
            'expires_at' => $response->json('expires_at'),
            'debe_cambiar_password' => false,
        ]);
        $this->assertNotEmpty($response->json('token'));
        $this->assertTrue(Carbon::parse($response->json('expires_at'))->equalTo(now()->addDays(30)));

        $token = $cuenta->tokens()->sole();
        $this->assertSame(['pro:acceso'], $token->abilities);
        $this->assertTrue($token->expires_at->equalTo(now()->addDays(30)));

        $cuenta->refresh();
        $this->assertTrue($cuenta->ultimo_login_at->equalTo(now()));
        $this->assertSame(self::IP, $cuenta->ultimo_login_ip);

        $this->app['auth']->forgetGuards();
        $this->withToken($response->json('token'))->getJson('/api/pro/me')
            ->assertOk()->assertJsonPath('cuenta.email', 'ana@x.com');
    }

    public function test_login_never_deletes_the_other_tokens_of_the_account(): void
    {
        $cuenta = $this->cuentaVinculada();
        $this->tokenPara($cuenta);
        $this->tokenPara($cuenta);

        $this->login('ana@x.com', 'password-seguro')->assertOk();

        $this->assertSame(3, $cuenta->tokens()->count());
    }

    public function test_every_failure_mode_gets_the_same_generic_401(): void
    {
        $this->cuentaVinculada('bien@x.com');
        $this->cuentaVinculada('inactiva@x.com', ['activo' => false], 'titular');

        $this->crearCuentaPortal('sinvinculo@x.com');

        $sinVinculoActivo = $this->crearCuentaPortal('vinculoinactivo@x.com');
        $this->vincularPortal($sinVinculoActivo, $this->negocio['bea'], activo: false);

        $profesionalInactiva = $this->crearProfesionalPortal($this->negocio['user'], 'Cami', activo: false);
        $conProfesionalInactiva = $this->crearCuentaPortal('profinactiva@x.com');
        $this->vincularPortal($conProfesionalInactiva, $profesionalInactiva);

        $casos = [
            'unknown email' => ['nadie@x.com', 'password-seguro'],
            'wrong password' => ['bien@x.com', 'otra-password'],
            'inactive account' => ['inactiva@x.com', 'password-seguro'],
            'account without links' => ['sinvinculo@x.com', 'password-seguro'],
            'only inactive link' => ['vinculoinactivo@x.com', 'password-seguro'],
            'only link to an inactive professional' => ['profinactiva@x.com', 'password-seguro'],
        ];

        $cuerpos = [];
        foreach ($casos as $caso => [$email, $password]) {
            $response = $this->login($email, $password);
            $this->assertSame(401, $response->getStatusCode(), $caso);
            $response->assertExactJson(['message' => self::MENSAJE]);
            $cuerpos[] = $response->getContent();
        }

        $this->assertCount(1, array_unique($cuerpos));
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_tenant_credentials_do_not_log_into_the_portal(): void
    {
        User::factory()->create(['email' => 'duena@x.com', 'password' => Hash::make('clave-tenant-1')]);

        $this->login('duena@x.com', 'clave-tenant-1')
            ->assertStatus(401)->assertExactJson(['message' => self::MENSAJE]);
    }

    public function test_malformed_fields_give_422_regardless_of_the_account_existing(): void
    {
        $this->cuentaVinculada();

        $malformados = [
            'missing password' => ['email' => 'ana@x.com'],
            'missing email' => ['password' => 'password-seguro'],
            'bad email' => ['email' => 'no-es-un-email', 'password' => 'password-seguro'],
            'password not a string' => ['email' => 'ana@x.com', 'password' => ['x']],
            'device label too long' => ['email' => 'ana@x.com', 'password' => 'password-seguro', 'dispositivo' => str_repeat('d', 101)],
            'password too long' => ['email' => 'ana@x.com', 'password' => str_repeat('p', 256)],
        ];

        foreach ($malformados as $caso => $payload) {
            $existente = $this->withServerVariables(['REMOTE_ADDR' => self::IP])->postJson('/api/pro/login', $payload);
            $this->assertSame(422, $existente->getStatusCode(), $caso);

            $desconocido = $this->withServerVariables(['REMOTE_ADDR' => self::IP])
                ->postJson('/api/pro/login', array_replace($payload, ($payload['email'] ?? null) === 'ana@x.com' ? ['email' => 'nadie@x.com'] : []));
            $this->assertSame($existente->getContent(), $desconocido->getContent(), $caso);
        }
    }

    public function test_unknown_email_still_runs_exactly_one_hash_check(): void
    {
        Hash::partialMock()->shouldReceive('check')->once()->andReturn(false);

        $this->login('nadie@x.com', 'password-seguro')->assertStatus(401);
    }

    public function test_existing_account_also_runs_exactly_one_hash_check(): void
    {
        $this->cuentaVinculada();
        Hash::partialMock()->shouldReceive('check')->once()->andReturn(true);

        $this->login('ana@x.com', 'password-seguro')->assertOk();
    }

    public function test_failed_login_logs_neither_the_email_nor_the_password(): void
    {
        $this->cuentaVinculada();
        Log::spy();

        $this->login('ana@x.com', 'clave-incorrecta-123')->assertStatus(401);

        Log::shouldHaveReceived('warning')->once();
        Log::shouldNotHaveReceived('warning', fn ($mensaje, $contexto = []) => str_contains(
            json_encode([$mensaje, $contexto]),
            'ana@x.com'
        ) || str_contains(json_encode([$mensaje, $contexto]), 'clave-incorrecta-123'));
    }

    public function test_dummy_hash_is_bcrypt_with_the_same_cost_as_the_admin_one(): void
    {
        $costo = fn (string $clase): int => password_get_info((new \ReflectionClassConstant($clase, 'HASH_FICTICIO'))->getValue())['options']['cost'];

        $this->assertSame(12, $costo(ProAuthController::class));
        $this->assertSame($costo(AdminAuthController::class), $costo(ProAuthController::class));
        $this->assertNotSame(
            (new \ReflectionClassConstant(AdminAuthController::class, 'HASH_FICTICIO'))->getValue(),
            (new \ReflectionClassConstant(ProAuthController::class, 'HASH_FICTICIO'))->getValue(),
        );
    }

    public function test_account_with_a_provisional_password_gets_only_a_restricted_token(): void
    {
        Carbon::setTestNow('2026-10-17 12:00:00');
        $cuenta = $this->cuentaVinculada('ana@x.com', ['debe_cambiar_password' => true]);

        $response = $this->login('ana@x.com', 'password-seguro');

        $response->assertOk()->assertJsonPath('debe_cambiar_password', true);
        $this->assertTrue(Carbon::parse($response->json('expires_at'))->equalTo(now()->addMinutes(15)));

        $token = $cuenta->tokens()->sole();
        $this->assertSame(['pro:cambiar-password'], $token->abilities);
        $this->assertTrue($token->expires_at->equalTo(now()->addMinutes(15)));

        $this->app['auth']->forgetGuards();
        $this->withToken($response->json('token'))->getJson('/api/pro/me')->assertForbidden();
    }

    public function test_restricted_login_replaces_the_previous_restricted_token_but_keeps_full_ones(): void
    {
        $cuenta = $this->cuentaVinculada('ana@x.com', ['debe_cambiar_password' => true]);
        $cuenta->createToken('pro-cambiar-password', ['pro:cambiar-password']);
        $this->tokenPara($cuenta, ['pro:acceso']);

        $this->login('ana@x.com', 'password-seguro')->assertOk();

        $this->assertSame(1, $cuenta->tokens()->get()->filter(fn ($t) => $t->abilities === ['pro:cambiar-password'])->count());
        $this->assertSame(1, $cuenta->tokens()->get()->filter(fn ($t) => $t->abilities === ['pro:acceso'])->count());
    }

    public function test_provisional_account_with_wrong_password_or_no_active_link_gets_no_token(): void
    {
        $this->cuentaVinculada('ana@x.com', ['debe_cambiar_password' => true]);
        $sinVinculo = $this->crearCuentaPortal('sinvinculo@x.com', 'password-seguro', ['debe_cambiar_password' => true]);

        $this->login('ana@x.com', 'otra-password')->assertStatus(401)->assertExactJson(['message' => self::MENSAJE]);
        $this->login('sinvinculo@x.com', 'password-seguro')->assertStatus(401)->assertExactJson(['message' => self::MENSAJE]);

        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame(0, $sinVinculo->tokens()->count());
    }

    public function test_kill_switch_off_answers_404_even_with_valid_credentials(): void
    {
        $this->cuentaVinculada();
        config(['portal_profesionales.habilitado' => false]);

        $this->login('ana@x.com', 'password-seguro')->assertNotFound();
        $this->assertSame(0, PersonalAccessToken::count());
    }
}

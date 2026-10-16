<?php

namespace Tests\Feature\Pro;

use App\Models\AdminUser;
use App\Models\ProfesionalCuenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use NotificationChannels\WebPush\PushSubscription;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * Guard isolation matrix (PA-11). Every request carries a REAL bearer token
 * (createToken): actingAs() yields a TransientToken that never exercises the
 * provider pinned to each Sanctum guard, which is exactly what is under test.
 *
 * Rows for later slices append to routes() and to the matriz() data provider.
 */
class ProGuardAislamientoTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private const TENANT = 'tenant';
    private const ADMIN = 'admin';
    private const PRO_FULL = 'pro_full';
    private const PRO_RESTRINGIDO = 'pro_restringido';

    /**
     * @return array{tenant: string, admin: string, pro_full: string, pro_restringido: string}
     */
    private function tokens(): array
    {
        $negocio = $this->crearNegocioPortal();
        $admin = AdminUser::create(['name' => 'Superadmin', 'email' => 'admin@turnetto.app', 'password' => Hash::make('password-seguro')]);
        $cuenta = $this->crearCuentaPortal();
        $this->vincularPortal($cuenta, $negocio['ana']);

        return [
            self::TENANT => $negocio['user']->createToken('app-mobile')->plainTextToken,
            self::ADMIN => $admin->createToken('admin-panel', ['*'])->plainTextToken,
            self::PRO_FULL => $this->tokenPara($cuenta, ['pro:acceso']),
            self::PRO_RESTRINGIDO => $this->tokenPara($cuenta, ['pro:cambiar-password']),
        ];
    }

    /**
     * Sends one request with a real bearer token. Sanctum's RequestGuard keeps
     * the user it resolved first for the lifetime of the application instance
     * (observed in this slice's first RED: a tenant token followed by a pro
     * token on /api/auth/me answered 200 for the second one), so every request
     * resets the guards. Without that, a test with several tokens would
     * measure the guard cache instead of the pinned provider.
     *
     * @param  array<string, mixed>  $datos
     */
    private function llamar(string $token, string $metodo, string $path, array $datos = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($metodo, $path, $datos);
    }

    /**
     * Routes the matrix runs against: key => [method, path]. Verified against
     * routes/api.php: push exposes public-key (GET) and subscriptions
     * (POST/DELETE); there is no "subscribe" path.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function routes(): array
    {
        return [
            'auth_me' => ['GET', '/api/auth/me'],
            'admin_me' => ['GET', '/api/admin/me'],
            'pro_me' => ['GET', '/api/pro/me'],
            // Empty body: an allowed caller gets past auth and stops at 422.
            'pro_cambiar_password' => ['POST', '/api/pro/cambiar-password'],
            'push_public_key' => ['GET', '/api/push/public-key'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function matriz(): array
    {
        return [
            // Same family: 200.
            'tenant -> auth/me' => [self::TENANT, 'auth_me', 200],
            'tenant -> push/public-key' => [self::TENANT, 'push_public_key', 200],
            'admin -> admin/me' => [self::ADMIN, 'admin_me', 200],
            'pro full -> pro/me' => [self::PRO_FULL, 'pro_me', 200],
            // Cross family: 401 in every direction.
            'tenant -> admin/me' => [self::TENANT, 'admin_me', 401],
            'tenant -> pro/me' => [self::TENANT, 'pro_me', 401],
            'admin -> auth/me' => [self::ADMIN, 'auth_me', 401],
            'admin -> pro/me' => [self::ADMIN, 'pro_me', 401],
            'admin -> push/public-key' => [self::ADMIN, 'push_public_key', 401],
            'pro full -> auth/me' => [self::PRO_FULL, 'auth_me', 401],
            'pro full -> admin/me' => [self::PRO_FULL, 'admin_me', 401],
            'pro full -> push/public-key' => [self::PRO_FULL, 'push_public_key', 401],
            // Restricted pro token: right family, wrong ability -> 403; elsewhere 401.
            'pro restringido -> pro/me' => [self::PRO_RESTRINGIDO, 'pro_me', 403],
            'pro restringido -> auth/me' => [self::PRO_RESTRINGIDO, 'auth_me', 401],
            'pro restringido -> admin/me' => [self::PRO_RESTRINGIDO, 'admin_me', 401],
            'pro restringido -> push/public-key' => [self::PRO_RESTRINGIDO, 'push_public_key', 401],
            // cambiar-password: only the restricted token gets in (422 = past auth, empty body).
            'pro restringido -> pro/cambiar-password' => [self::PRO_RESTRINGIDO, 'pro_cambiar_password', 422],
            'pro full -> pro/cambiar-password' => [self::PRO_FULL, 'pro_cambiar_password', 403],
            'tenant -> pro/cambiar-password' => [self::TENANT, 'pro_cambiar_password', 401],
            'admin -> pro/cambiar-password' => [self::ADMIN, 'pro_cambiar_password', 401],
        ];
    }

    #[DataProvider('matriz')]
    public function test_matrix_row(string $familia, string $ruta, int $estado): void
    {
        config(['webpush.vapid.public_key' => 'BPublicKeyValue']);
        $tokens = $this->tokens();
        [$metodo, $path] = $this->routes()[$ruta];

        $this->llamar($tokens[$familia], $metodo, $path)->assertStatus($estado);
    }

    public function test_pro_route_without_token_is_401(): void
    {
        $this->getJson('/api/pro/me')->assertUnauthorized();
    }

    public function test_id_collision_does_not_cross_authenticate(): void
    {
        $tokens = $this->tokens();

        // The three identities share primary key 1: only the tokenable CLASS can tell them apart.
        $this->assertSame(1, User::query()->min('id'));
        $this->assertSame(1, AdminUser::query()->min('id'));
        $this->assertSame(1, ProfesionalCuenta::query()->min('id'));

        $this->llamar($tokens[self::PRO_FULL], 'GET', '/api/auth/me')->assertUnauthorized();
        $this->llamar($tokens[self::PRO_FULL], 'GET', '/api/admin/me')->assertUnauthorized();
        $this->llamar($tokens[self::TENANT], 'GET', '/api/pro/me')->assertUnauthorized();
        $this->llamar($tokens[self::TENANT], 'GET', '/api/admin/me')->assertUnauthorized();
        $this->llamar($tokens[self::ADMIN], 'GET', '/api/pro/me')->assertUnauthorized();
        $this->llamar($tokens[self::ADMIN], 'GET', '/api/auth/me')->assertUnauthorized();
    }

    public function test_alternating_families_in_one_test_keeps_each_provider_pinned(): void
    {
        $tokens = $this->tokens();

        $this->llamar($tokens[self::TENANT], 'GET', '/api/auth/me')->assertOk();
        $this->llamar($tokens[self::PRO_FULL], 'GET', '/api/auth/me')->assertUnauthorized();
        $this->llamar($tokens[self::PRO_FULL], 'GET', '/api/pro/me')->assertOk()
            ->assertJsonPath('cuenta.email', 'ana@x.com');
        $this->llamar($tokens[self::TENANT], 'GET', '/api/pro/me')->assertUnauthorized();
        $this->llamar($tokens[self::ADMIN], 'GET', '/api/admin/me')->assertOk();
        $this->llamar($tokens[self::PRO_FULL], 'GET', '/api/admin/me')->assertUnauthorized();
    }

    public function test_pro_token_cannot_create_a_push_subscription(): void
    {
        $tokens = $this->tokens();

        $this->llamar($tokens[self::PRO_FULL], 'POST', '/api/push/subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'BKey-public', 'auth' => 'auth-secret'],
        ])->assertUnauthorized();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_profesional_guard_is_pinned_to_its_own_provider(): void
    {
        $this->assertSame('sanctum', config('auth.guards.profesional.driver'));
        $this->assertSame('profesional_cuentas', config('auth.guards.profesional.provider'));
        $this->assertSame(ProfesionalCuenta::class, config('auth.providers.profesional_cuentas.model'));
        $this->assertSame('eloquent', config('auth.providers.profesional_cuentas.driver'));
    }
}

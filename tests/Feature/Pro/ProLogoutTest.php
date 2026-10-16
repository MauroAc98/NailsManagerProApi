<?php

namespace Tests\Feature\Pro;

use App\Models\ProfesionalCuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * PA-09: logout revokes only the presented token. A restricted token may also
 * log out (a provisional-password session must be able to end itself).
 */
class ProLogoutTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private ProfesionalCuenta $cuenta;

    protected function setUp(): void
    {
        parent::setUp();
        $negocio = $this->crearNegocioPortal();
        $this->cuenta = $this->crearCuentaPortal();
        $this->vincularPortal($this->cuenta, $negocio['ana']);
    }

    private function llamar(string $metodo, string $uri, string $token)
    {
        // Sanctum's guard caches the first resolved user per app instance.
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($metodo, $uri);
    }

    public function test_logout_revokes_only_the_presented_token(): void
    {
        $dispositivo1 = $this->tokenPara($this->cuenta);
        $dispositivo2 = $this->tokenPara($this->cuenta);

        $this->llamar('POST', '/api/pro/logout', $dispositivo1)->assertNoContent();

        $this->assertSame(1, $this->cuenta->tokens()->count());
        $this->llamar('GET', '/api/pro/me', $dispositivo1)->assertUnauthorized();
        $this->llamar('GET', '/api/pro/me', $dispositivo2)->assertOk();
    }

    public function test_repeating_logout_with_the_revoked_token_is_unauthorized(): void
    {
        $token = $this->tokenPara($this->cuenta);

        $this->llamar('POST', '/api/pro/logout', $token)->assertNoContent();
        $this->llamar('POST', '/api/pro/logout', $token)->assertUnauthorized();
    }

    public function test_a_restricted_token_can_log_out_and_keeps_the_other_tokens(): void
    {
        $completo = $this->tokenPara($this->cuenta);
        $restringido = $this->tokenPara($this->cuenta, ['pro:cambiar-password']);

        $this->llamar('POST', '/api/pro/logout', $restringido)->assertNoContent();

        $this->assertSame(1, PersonalAccessToken::count());
        $this->llamar('GET', '/api/pro/me', $completo)->assertOk();
    }

    public function test_logout_without_a_token_is_unauthorized(): void
    {
        $this->postJson('/api/pro/logout')->assertUnauthorized();
    }
}

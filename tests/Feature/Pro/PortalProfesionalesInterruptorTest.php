<?php

namespace Tests\Feature\Pro;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * Kill switch of the professionals portal (PA-12): a runtime flag, honoured
 * by the first middleware of the /api/pro group so that even unauthenticated
 * callers get a 404 while the portal is disabled.
 */
class PortalProfesionalesInterruptorTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    public function test_flag_is_enabled_in_the_test_environment_and_off_by_default_in_code(): void
    {
        $this->assertTrue((bool) config('portal_profesionales.habilitado'));

        // phpunit.xml overrides the env var, so the code default can only be
        // pinned by reading the config source.
        $this->assertStringContainsString(
            "env('PORTAL_PROFESIONALES_HABILITADO', false)",
            file_get_contents(base_path('config/portal_profesionales.php'))
        );
        $this->assertNotEmpty(config('portal_profesionales.url'));
    }

    public function test_flag_off_answers_404_on_me_even_with_a_valid_token(): void
    {
        $negocio = $this->crearNegocioPortal();
        $cuenta = $this->crearCuentaPortal();
        $this->vincularPortal($cuenta, $negocio['ana']);
        $token = $this->tokenPara($cuenta);

        // Sanity: with the portal enabled the same token is accepted.
        $this->withToken($token)->getJson('/api/pro/me')->assertOk();

        config(['portal_profesionales.habilitado' => false]);

        $this->withToken($token)->getJson('/api/pro/me')->assertNotFound();
    }

    public function test_flag_off_answers_404_without_authentication_and_on_login(): void
    {
        config(['portal_profesionales.habilitado' => false]);

        $this->getJson('/api/pro/me')->assertNotFound();
        $this->postJson('/api/pro/login', ['email' => 'ana@x.com', 'password' => 'password-seguro'])->assertNotFound();
        $this->getJson('/api/pro/cualquier-cosa')->assertNotFound();
    }

    public function test_flag_on_requires_authentication_on_me(): void
    {
        config(['portal_profesionales.habilitado' => true]);

        $this->getJson('/api/pro/me')->assertUnauthorized();
    }

    public function test_flag_off_does_not_affect_the_other_families(): void
    {
        config(['portal_profesionales.habilitado' => false]);

        // Tenant and admin routes keep answering their own 401, not the portal's 404.
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/admin/me')->assertUnauthorized();
    }
}

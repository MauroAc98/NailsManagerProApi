<?php

namespace Tests\Feature\Pro;

use App\Models\AdminUser;
use App\Models\Profesional;
use App\Models\ProfesionalCuenta;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * AM-08 + PA-12 S3 for EVERY owner-side access endpoint: only the persona
 * titular of the professional's own negocio can use them, with a tenant token,
 * a valid subscription and the portal switched on. A pro or admin token, an
 * expired subscription, the kill switch and another negocio's titular all stop
 * the request before anything is read, changed or mailed.
 */
class ProfesionalAccesoAislamientoTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private const NO_ENCONTRADA = [
        'message' => 'No encontramos a esa profesional.',
        'code' => 'PROFESIONAL_NO_ENCONTRADA',
    ];

    /** @var array{user: User, titular: Profesional, ana: Profesional, bea: Profesional} */
    private array $negocio;

    /** @var array{user: User, titular: Profesional, ana: Profesional, bea: Profesional} */
    private array $otroNegocio;

    private ProfesionalCuenta $cuenta;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->negocio = $this->crearNegocioPortal(['email' => 'titular@negocio.test']);
        $this->otroNegocio = $this->crearNegocioPortal(['email' => 'otra.titular@negocio.test']);
        // A pending account created by the first negocio's invitation of Ana: the one case a reset would actually act on.
        $this->cuenta = $this->crearCuentaPortal('ana@x.com', 'provisoria-1', ['debe_cambiar_password' => true]);
        $this->vincularPortal($this->cuenta, $this->negocio['ana'], true, null, true);
    }

    /**
     * Every owner-side endpoint. `{id}` is the professional the request names.
     *
     * @return array<string, array{string, string, array<string, mixed>, bool}> method, path, body, takes a professional id
     */
    public static function endpoints(): array
    {
        return [
            'list' => ['GET', '/api/profesionales/accesos', [], false],
            'status' => ['GET', '/api/profesionales/{id}/acceso', [], true],
            'invite' => ['POST', '/api/profesionales/{id}/acceso', ['email' => 'nueva@x.com'], true],
            'toggle' => ['PATCH', '/api/profesionales/{id}/acceso', ['activo' => false], true],
            'remove' => ['DELETE', '/api/profesionales/{id}/acceso', [], true],
            'reset' => ['POST', '/api/profesionales/{id}/acceso/reset', [], true],
        ];
    }

    /** @return array<string, array{string, string, array<string, mixed>, bool}> */
    public static function endpointsConId(): array
    {
        return array_filter(self::endpoints(), fn (array $e) => $e[3]);
    }

    private function llamar(string $metodo, string $ruta, array $cuerpo, ?string $token, int $profesionalId)
    {
        $this->app['auth']->forgetGuards();
        $cliente = $token === null ? $this : $this->withToken($token);

        return $cliente->json($metodo, str_replace('{id}', (string) $profesionalId, $ruta), $cuerpo);
    }

    private function tokenTitular(): string
    {
        return $this->negocio['user']->createToken('test')->plainTextToken;
    }

    /** @return array<string, array<string, mixed>> every row that a request could change, to prove it did not */
    private function estadoDelPortal(): array
    {
        return [
            'cuentas' => DB::table('profesional_cuentas')->orderBy('id')->get()->map(fn ($f) => (array) $f)->all(),
            'vinculos' => DB::table('profesional_vinculos')->orderBy('id')->get()->map(fn ($f) => (array) $f)->all(),
            'tokens' => DB::table('personal_access_tokens')->where('tokenable_type', ProfesionalCuenta::class)->orderBy('id')->pluck('id')->all(),
        ];
    }

    // ── Another titular ─────────────────────────────────────────────────────

    #[DataProvider('endpointsConId')]
    public function test_another_titular_gets_the_fixed_404_and_nothing_changes(string $metodo, string $ruta, array $cuerpo): void
    {
        $antes = $this->estadoDelPortal();
        $tokenOtra = $this->otroNegocio['user']->createToken('test')->plainTextToken;

        $this->llamar($metodo, $ruta, $cuerpo, $tokenOtra, $this->negocio['ana']->id)
            ->assertNotFound()
            ->assertExactJson(self::NO_ENCONTRADA);

        $this->assertSame($antes, $this->estadoDelPortal());
        Mail::assertNothingSent();
    }

    public function test_the_list_of_another_titular_never_includes_these_professionals(): void
    {
        $tokenOtra = $this->otroNegocio['user']->createToken('test')->plainTextToken;

        $respuesta = $this->llamar('GET', '/api/profesionales/accesos', [], $tokenOtra, 0)->assertOk();

        $ids = array_column($respuesta->json('data'), 'profesional_id');
        $this->assertEqualsCanonicalizing(
            [$this->otroNegocio['titular']->id, $this->otroNegocio['ana']->id, $this->otroNegocio['bea']->id],
            $ids,
        );
        $this->assertStringNotContainsString('ana@x.com', $respuesta->getContent());
    }

    // ── Wrong kind of token ─────────────────────────────────────────────────

    #[DataProvider('endpoints')]
    public function test_a_pro_token_is_rejected_with_401_and_nothing_changes(string $metodo, string $ruta, array $cuerpo): void
    {
        $antes = $this->estadoDelPortal();
        $completo = $this->tokenPara($this->cuenta);
        $restringido = $this->tokenPara($this->cuenta, ['pro:cambiar-password']);

        foreach ([$completo, $restringido] as $token) {
            $this->llamar($metodo, $ruta, $cuerpo, $token, $this->negocio['ana']->id)->assertUnauthorized();
        }

        // The two tokens above are the only change: they are the account's own.
        $this->assertSame($antes['cuentas'], $this->estadoDelPortal()['cuentas']);
        $this->assertSame($antes['vinculos'], $this->estadoDelPortal()['vinculos']);
        Mail::assertNothingSent();
    }

    #[DataProvider('endpoints')]
    public function test_an_admin_token_is_rejected_with_401(string $metodo, string $ruta, array $cuerpo): void
    {
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'admin@turnetto.test', 'password' => 'password-de-admin']);
        $antes = $this->estadoDelPortal();

        $this->llamar($metodo, $ruta, $cuerpo, $admin->createToken('admin')->plainTextToken, $this->negocio['ana']->id)
            ->assertUnauthorized();

        $this->assertSame($antes, $this->estadoDelPortal());
        Mail::assertNothingSent();
    }

    #[DataProvider('endpoints')]
    public function test_no_token_is_rejected_with_401(string $metodo, string $ruta, array $cuerpo): void
    {
        $antes = $this->estadoDelPortal();

        $this->llamar($metodo, $ruta, $cuerpo, null, $this->negocio['ana']->id)->assertUnauthorized();

        $this->assertSame($antes, $this->estadoDelPortal());
    }

    // ── Subscription ────────────────────────────────────────────────────────

    #[DataProvider('endpoints')]
    public function test_an_expired_subscription_is_blocked_with_the_tenant_code_and_nothing_changes(string $metodo, string $ruta, array $cuerpo): void
    {
        $vencido = $this->crearNegocioPortal(['is_exempt' => false]);
        Subscription::create(['user_id' => $vencido['user']->id, 'ends_at' => now()->subDay(), 'status' => 'ACTIVO']);
        $cuentaVencida = $this->crearCuentaPortal('vencida@x.com', 'provisoria-2', ['debe_cambiar_password' => true]);
        $this->vincularPortal($cuentaVencida, $vencido['ana']);
        $antes = $this->estadoDelPortal();

        $this->llamar($metodo, $ruta, $cuerpo, $vencido['user']->createToken('test')->plainTextToken, $vencido['ana']->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_EXPIRED');

        $this->assertSame($antes, $this->estadoDelPortal());
        Mail::assertNothingSent();
    }

    // ── Kill switch ─────────────────────────────────────────────────────────

    #[DataProvider('endpoints')]
    public function test_with_the_kill_switch_off_every_endpoint_is_a_404_and_nothing_changes(string $metodo, string $ruta, array $cuerpo): void
    {
        $token = $this->tokenTitular();
        $antes = $this->estadoDelPortal();
        config(['portal_profesionales.habilitado' => false]);

        $this->llamar($metodo, $ruta, $cuerpo, $token, $this->negocio['ana']->id)->assertNotFound();
        $this->llamar($metodo, $ruta, $cuerpo, null, $this->negocio['ana']->id)->assertNotFound();

        $this->assertSame($antes, $this->estadoDelPortal());
        Mail::assertNothingSent();
    }

    // ── Sanity: the same requests work for the right caller ────────────────

    #[DataProvider('endpoints')]
    public function test_the_titular_of_the_negocio_can_reach_every_endpoint(string $metodo, string $ruta, array $cuerpo): void
    {
        // Ana already has access under ana@x.com: inviting that same email again is the idempotent 200.
        if (isset($cuerpo['email'])) {
            $cuerpo['email'] = 'ana@x.com';
        }

        $this->llamar($metodo, $ruta, $cuerpo, $this->tokenTitular(), $this->negocio['ana']->id)->assertOk();
    }
}

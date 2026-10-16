<?php

namespace Tests\Feature\Pro;

use App\Models\ProfesionalCuenta;
use App\Services\Portal\EmisorTokenProfesional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * PA-05 device policy: up to 5 live full tokens per account. A new login never
 * deletes other tokens until the cap is reached, then it evicts only the least
 * recently used one. Expired and restricted tokens do not count.
 */
class ProDispositivosTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private const IP = '10.8.8.8';

    private ProfesionalCuenta $cuenta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 12:00:00');
        $negocio = $this->crearNegocioPortal();
        $this->cuenta = $this->crearCuentaPortal('ana@x.com', 'password-seguro');
        $this->vincularPortal($this->cuenta, $negocio['ana']);
    }

    /**
     * A token with explicit timestamps. $usado null means never used.
     *
     * @return array{0: string, 1: PersonalAccessToken}
     */
    private function tokenConFechas(string $creado, ?string $usado = null, ?string $expira = '+30 days', string $nombre = 'pro-portal'): array
    {
        $nuevo = $this->cuenta->createToken($nombre, ['pro:acceso'], $expira ? Carbon::parse($creado)->modify($expira) : null);
        $nuevo->accessToken->forceFill([
            'created_at' => Carbon::parse($creado),
            'last_used_at' => $usado ? Carbon::parse($usado) : null,
        ])->save();

        return [$nuevo->plainTextToken, $nuevo->accessToken];
    }

    private function login(): string
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->postJson('/api/pro/login', ['email' => 'ana@x.com', 'password' => 'password-seguro']);
        $response->assertOk();

        return $response->json('token');
    }

    private function statusConToken(string $plain): int
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($plain)->getJson('/api/pro/me')->getStatusCode();
    }

    public function test_login_below_the_cap_keeps_every_existing_token_working(): void
    {
        [$a] = $this->tokenConFechas('2026-10-10 10:00:00');
        [$b] = $this->tokenConFechas('2026-10-11 10:00:00');

        $nuevo = $this->login();

        $this->assertSame(3, $this->cuenta->tokens()->count());
        $this->assertSame(200, $this->statusConToken($a));
        $this->assertSame(200, $this->statusConToken($b));
        $this->assertSame(200, $this->statusConToken($nuevo));
    }

    public function test_sixth_login_evicts_only_the_least_recently_used_token(): void
    {
        // Oldest by creation but used an hour ago: must survive.
        [, $reciente] = $this->tokenConFechas('2026-10-01 10:00:00', '2026-10-17 11:00:00');
        // Never used, created 4 days ago: its last activity is its creation, the LRU.
        [$plainLru, $lru] = $this->tokenConFechas('2026-10-13 10:00:00');
        [, $c] = $this->tokenConFechas('2026-10-05 10:00:00', '2026-10-15 10:00:00');
        [, $d] = $this->tokenConFechas('2026-10-06 10:00:00', '2026-10-16 10:00:00');
        [, $e] = $this->tokenConFechas('2026-10-07 10:00:00', '2026-10-14 10:00:00');

        $nuevo = $this->login();

        $this->assertNull(PersonalAccessToken::find($lru->id));
        foreach ([$reciente, $c, $d, $e] as $sobreviviente) {
            $this->assertNotNull(PersonalAccessToken::find($sobreviviente->id));
        }
        $this->assertSame(5, $this->cuenta->tokens()->count());
        $this->assertSame(401, $this->statusConToken($plainLru));
        $this->assertSame(200, $this->statusConToken($nuevo));
    }

    public function test_a_token_never_used_is_ranked_by_its_creation_date(): void
    {
        // Used long ago vs never used but created even earlier: the never-used one is the LRU.
        [, $usadoHaceMucho] = $this->tokenConFechas('2026-09-20 10:00:00', '2026-10-02 10:00:00', '+60 days');
        [, $nuncaUsado] = $this->tokenConFechas('2026-10-01 10:00:00');
        foreach (['2026-10-10', '2026-10-11', '2026-10-12'] as $dia) {
            $this->tokenConFechas("$dia 10:00:00", "$dia 11:00:00");
        }

        $this->login();

        $this->assertNull(PersonalAccessToken::find($nuncaUsado->id));
        $this->assertNotNull(PersonalAccessToken::find($usadoHaceMucho->id));
    }

    public function test_a_recently_created_unused_token_outranks_one_used_long_ago(): void
    {
        // Never used but created yesterday: recent activity, must survive (on
        // Postgres NULLs sort last, on sqlite first: the result must not depend on it).
        [, $nuevoSinUso] = $this->tokenConFechas('2026-10-16 10:00:00');
        [, $usadoHaceMucho] = $this->tokenConFechas('2026-09-01 10:00:00', '2026-09-20 10:00:00', '+60 days');
        foreach (['2026-10-10', '2026-10-11', '2026-10-12'] as $dia) {
            $this->tokenConFechas("$dia 10:00:00", "$dia 11:00:00");
        }

        $this->login();

        $this->assertNull(PersonalAccessToken::find($usadoHaceMucho->id));
        $this->assertNotNull(PersonalAccessToken::find($nuevoSinUso->id));
    }

    public function test_expired_tokens_do_not_count_toward_the_cap(): void
    {
        foreach (['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13'] as $dia) {
            $this->tokenConFechas("$dia 10:00:00");
        }
        [, $vencido] = $this->tokenConFechas('2026-08-01 10:00:00');

        $this->login();

        $this->assertSame(6, $this->cuenta->tokens()->count());
        $this->assertNotNull(PersonalAccessToken::find($vencido->id));
    }

    public function test_the_restricted_token_does_not_count_and_is_never_evicted(): void
    {
        $restringido = $this->cuenta->createToken(EmisorTokenProfesional::NOMBRE_RESTRINGIDO, ['pro:cambiar-password'], now()->addMinutes(15));
        foreach (['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13'] as $dia) {
            $this->tokenConFechas("$dia 10:00:00");
        }

        $this->login();

        // 4 full + new = 5 full: nobody evicted although the account holds 6 rows.
        $this->assertSame(6, $this->cuenta->tokens()->count());

        $this->login();

        // Now a 6th full login: exactly one full token goes, the restricted one stays.
        $this->assertSame(6, $this->cuenta->tokens()->count());
        $this->assertNotNull(PersonalAccessToken::find($restringido->accessToken->id));
    }

    public function test_the_expiry_is_fixed_and_not_extended_by_use(): void
    {
        $token = $this->login();
        $expiraEn = $this->cuenta->tokens()->sole()->expires_at;

        Carbon::setTestNow('2026-11-15 12:00:00'); // t0 + 29 days
        $this->assertSame(200, $this->statusConToken($token));
        $this->assertTrue($this->cuenta->tokens()->sole()->expires_at->equalTo($expiraEn));

        Carbon::setTestNow('2026-11-16 12:00:01'); // t0 + 30 days + 1 second
        $this->assertSame(401, $this->statusConToken($token));
    }
}

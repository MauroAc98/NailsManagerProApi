<?php

namespace Tests\Feature\Pro;

use App\Models\ProfesionalCuenta;
use App\Models\User;
use App\Support\BloqueoLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * Lockout and throttling of POST /api/pro/login (PA-04, D11), end to end: the
 * (email, IP) pair locks at 5 failures, the IP at 30, never the email alone,
 * and a locked response is a 429 with Retry-After even for the right password.
 */
class ProLoginBloqueoTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private const IP = '10.8.8.8';

    private ProfesionalCuenta $cuenta;

    protected function setUp(): void
    {
        parent::setUp();
        $negocio = $this->crearNegocioPortal();
        $this->cuenta = $this->crearCuentaPortal('ana@x.com', 'password-seguro');
        $this->vincularPortal($this->cuenta, $negocio['ana']);
    }

    private function login(string $email, string $password, string $ip = self::IP)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/pro/login', ['email' => $email, 'password' => $password]);
    }

    private function fallar(int $veces, string $email = 'ana@x.com', string $ip = self::IP): void
    {
        for ($i = 0; $i < $veces; $i++) {
            $this->login($email, 'password-incorrecta', $ip)->assertStatus(401);
        }
    }

    public function test_five_failures_lock_the_pair_even_for_the_correct_password(): void
    {
        $this->fallar(5);

        $response = $this->login('ana@x.com', 'password-seguro');

        $response->assertStatus(429)->assertJsonStructure(['message', 'retry_after']);
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertSame((int) $response->headers->get('Retry-After'), $response->json('retry_after'));
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_four_failures_do_not_lock_and_the_correct_password_still_works(): void
    {
        $this->fallar(4);

        $this->login('ana@x.com', 'password-seguro')->assertOk();
    }

    public function test_the_lock_is_not_by_email_alone(): void
    {
        $this->fallar(5);

        $this->login('ana@x.com', 'password-seguro', '10.8.9.9')->assertOk();
    }

    public function test_failures_spread_over_one_hundred_ips_do_not_lock_the_real_user(): void
    {
        for ($i = 1; $i <= 100; $i++) {
            $this->login('ana@x.com', 'password-incorrecta', "10.9.{$i}.1")->assertStatus(401);
        }

        $this->login('ana@x.com', 'password-seguro', '10.8.9.9')->assertOk();
    }

    public function test_thirty_failures_from_one_ip_against_different_emails_lock_that_ip_only(): void
    {
        // 25 failures are recorded straight on the profile: the route throttle
        // (20/min/IP) would answer before the 30th request of a real loop.
        for ($i = 1; $i <= 25; $i++) {
            BloqueoLogin::profesional()->registrarFallo("probe{$i}@x.com", self::IP);
        }
        $this->fallar(1, 'nadie1@x.com');
        $this->fallar(1, 'nadie2@x.com');
        $this->fallar(1, 'nadie3@x.com');
        $this->fallar(1, 'nadie4@x.com');
        $this->fallar(1, 'nadie5@x.com');

        $this->login('ana@x.com', 'password-seguro')->assertStatus(429)->assertJsonStructure(['retry_after']);
        $this->login('ana@x.com', 'password-seguro', '10.8.9.9')->assertOk();
    }

    public function test_unknown_and_existing_emails_lock_with_the_same_response_shape(): void
    {
        $this->fallar(5, 'nadie@x.com');
        $this->fallar(5, 'ana@x.com', '10.8.7.7');

        $desconocido = $this->login('nadie@x.com', 'password-seguro');
        $existente = $this->login('ana@x.com', 'password-seguro', '10.8.7.7');

        $desconocido->assertStatus(429);
        $existente->assertStatus(429);
        $this->assertSame($existente->json('message'), $desconocido->json('message'));
        $this->assertSame(array_keys($existente->json()), array_keys($desconocido->json()));
    }

    public function test_a_successful_login_clears_the_pair_counter(): void
    {
        $this->fallar(4);
        $this->login('ana@x.com', 'password-seguro')->assertOk();
        $this->fallar(4);

        $this->login('ana@x.com', 'password-seguro')->assertOk();
    }

    public function test_malformed_requests_do_not_count_as_failures(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => self::IP])
                ->postJson('/api/pro/login', ['email' => 'ana@x.com'])->assertStatus(422);
        }

        $this->login('ana@x.com', 'password-seguro')->assertOk();
    }

    public function test_failures_on_tenant_and_admin_login_never_lock_the_portal_login(): void
    {
        User::factory()->create(['email' => 'ana@x.com', 'password' => Hash::make('clave-tenant-1')]);

        // Each of the other two logins ends up locked for the same email and IP...
        // (The unnamed route throttles are keyed by IP only and shared between
        // routes, so the admin attempts use another IP of their own.)
        $intentar = fn (string $ruta, string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson($ruta, ['email' => 'ana@x.com', 'password' => 'incorrecta']);
        for ($i = 0; $i < 5; $i++) {
            $intentar('/api/auth/login', self::IP)->assertStatus(422);
        }
        $intentar('/api/auth/login', self::IP)->assertStatus(429);
        for ($i = 0; $i < 3; $i++) {
            $intentar('/api/admin/login', '10.8.5.5')->assertStatus(401);
        }
        $intentar('/api/admin/login', '10.8.5.5')->assertStatus(429);

        // ...and the portal login is still open from both IPs.
        $this->login('ana@x.com', 'password-seguro', '10.8.5.5')->assertOk();
        $this->login('ana@x.com', 'password-seguro')->assertOk();
    }

    public function test_route_throttle_answers_429_on_the_twenty_first_request_in_a_minute(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => self::IP])->postJson('/api/pro/login', [])->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => self::IP])->postJson('/api/pro/login', [])
            ->assertStatus(429)->assertHeader('Retry-After');
        // Another IP is untouched.
        $this->withServerVariables(['REMOTE_ADDR' => '10.8.9.9'])->postJson('/api/pro/login', [])->assertStatus(422);
    }
}

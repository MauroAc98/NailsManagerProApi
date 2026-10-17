<?php

namespace Tests\Feature\Pro;

use App\Mail\ProfesionalResetCodeMail;
use App\Models\ProfesionalCuenta;
use App\Models\User;
use App\Services\Portal\RestablecerPasswordConCodigo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * PR-02: the account holder sets a new password with the emailed code. Every
 * way of failing must look the same from outside, the attempt counter must be
 * in the database and impossible to dodge, and a code works exactly once.
 */
class ProRestablecerPasswordTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private const URL = '/api/pro/reset-password';

    private const TABLA = 'profesional_cuenta_reset_codes';

    private const CODIGO = '482915';

    private const PASSWORD_NUEVA = 'clave-nueva-segura';

    private ProfesionalCuenta $cuenta;

    protected function setUp(): void
    {
        parent::setUp();
        $negocio = $this->crearNegocioPortal();
        $this->cuenta = $this->crearCuentaPortal('ana@x.com', 'clave-vieja-1234');
        $this->vincularPortal($this->cuenta, $negocio['ana']);
        Carbon::setTestNow(Carbon::parse('2026-10-17 12:00:00'));
    }

    /**
     * Stores a live code for the account (like forgot-password does) and returns it.
     * Pass `code_hash` in $attrs to skip the hashing (tests that count hash calls).
     */
    private function conCodigo(?ProfesionalCuenta $cuenta = null, array $attrs = [], string $codigo = self::CODIGO): string
    {
        DB::table(self::TABLA)->insert(array_merge([
            'cuenta_id' => ($cuenta ?? $this->cuenta)->id,
            'code_hash' => $attrs['code_hash'] ?? Hash::make($codigo),
            'expira_en' => now()->timestamp + 1800,
            'ultimo_envio_en' => now()->timestamp,
            'intentos' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        return $codigo;
    }

    private function reset(array $cuerpo = [], string $ip = '10.1.0.1')
    {
        $this->reiniciarTareasPostRespuesta();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(self::URL, array_merge([
            'email' => 'ana@x.com',
            'code' => self::CODIGO,
            'password' => self::PASSWORD_NUEVA,
            'password_confirmation' => self::PASSWORD_NUEVA,
        ], $cuerpo));
    }

    private function fila(?ProfesionalCuenta $cuenta = null): ?object
    {
        return DB::table(self::TABLA)->where('cuenta_id', ($cuenta ?? $this->cuenta)->id)->first();
    }

    private function intentos(): int
    {
        return (int) $this->fila()->intentos;
    }

    private function passwordSigueIgual(): bool
    {
        return Hash::check('clave-vieja-1234', $this->cuenta->fresh()->password);
    }

    private function login(string $password)
    {
        $this->reiniciarTareasPostRespuesta();

        return $this->withServerVariables(['REMOTE_ADDR' => '10.1.9.9'])
            ->postJson('/api/pro/login', ['email' => 'ana@x.com', 'password' => $password]);
    }

    /** What a caller can observe of a failure: status, body and headers (minus per-request noise). */
    private function huella($respuesta): array
    {
        $cabeceras = $respuesta->headers->all();
        unset($cabeceras['date'], $cabeceras['x-ratelimit-remaining']);
        ksort($cabeceras);

        return [$respuesta->getStatusCode(), $respuesta->getContent(), $cabeceras];
    }

    // ───────── success ─────────

    public function test_a_successful_reset_clears_the_login_lockout_of_that_email_and_ip_only(): void
    {
        $bloqueo = \App\Support\BloqueoLogin::profesional();
        for ($i = 0; $i < 5; $i++) {
            $bloqueo->registrarFallo('ana@x.com', '10.1.9.9');
            $bloqueo->registrarFallo('ana@x.com', '10.1.9.8');
        }
        $this->login(self::PASSWORD_NUEVA)->assertStatus(429);

        $this->conCodigo();
        $this->reset([], '10.1.9.9')->assertOk();

        // The person who proved control of the mailbox can sign in again from there...
        $this->login(self::PASSWORD_NUEVA)->assertOk();
        // ...while the pair from another IP stays locked.
        $this->assertNotNull($bloqueo->segundosDeBloqueo('ana@x.com', '10.1.9.8'));
    }

    public function test_a_valid_code_sets_the_new_password_and_issues_no_token(): void
    {
        $this->conCodigo();

        $respuesta = $this->reset();

        $respuesta->assertOk();
        $this->assertSame(['message'], array_keys($respuesta->json()));
        $this->assertStringNotContainsString('token', strtolower($respuesta->getContent()));
        $this->assertTrue(Hash::check(self::PASSWORD_NUEVA, $this->cuenta->fresh()->password));
        $this->login(self::PASSWORD_NUEVA)->assertOk()->assertJsonPath('debe_cambiar_password', false);
    }

    public function test_the_old_password_stops_working(): void
    {
        $this->conCodigo();

        $this->reset()->assertOk();

        $this->login('clave-vieja-1234')->assertUnauthorized();
    }

    public function test_all_tokens_of_the_account_are_revoked_but_not_those_of_other_accounts(): void
    {
        $otra = $this->crearCuentaPortal('otra@x.com');
        $this->vincularPortal($otra, $this->crearProfesionalPortal(User::first(), 'Otra'));
        $this->tokenPara($this->cuenta);
        $this->tokenPara($this->cuenta);
        $this->tokenPara($this->cuenta, ['pro:cambiar-password']);
        $this->tokenPara($otra);
        $this->conCodigo();
        $this->assertSame(3, $this->cuenta->tokens()->count());

        $this->reset()->assertOk();

        $this->assertSame(0, $this->cuenta->tokens()->count());
        $this->assertSame(1, $otra->tokens()->count());
    }

    public function test_the_code_is_burned_and_cannot_be_used_twice(): void
    {
        $this->conCodigo();

        $this->reset()->assertOk();
        $this->assertNull($this->fila());

        $segundo = $this->reset(['password' => 'otra-clave-distinta', 'password_confirmation' => 'otra-clave-distinta']);
        $segundo->assertUnprocessable();
        $this->assertTrue(Hash::check(self::PASSWORD_NUEVA, $this->cuenta->fresh()->password), 'a replay must not change the password');
    }

    public function test_an_account_with_a_provisional_password_gets_a_full_token_afterwards(): void
    {
        $this->cuenta->forceFill(['debe_cambiar_password' => true])->save();
        $this->conCodigo();
        $this->assertTrue($this->login('clave-vieja-1234')->json('debe_cambiar_password'));

        $this->reset()->assertOk();

        $this->assertFalse($this->cuenta->fresh()->debe_cambiar_password);
        $login = $this->login(self::PASSWORD_NUEVA);
        $login->assertOk()->assertJsonPath('debe_cambiar_password', false);
        $token = PersonalAccessToken::findToken($login->json('token'));
        $this->assertSame(['pro:acceso'], $token->abilities);
    }

    public function test_the_email_is_normalized(): void
    {
        $this->conCodigo();

        $this->reset(['email' => '  ANA@X.com '])->assertOk();

        $this->assertTrue(Hash::check(self::PASSWORD_NUEVA, $this->cuenta->fresh()->password));
    }

    public function test_the_last_allowed_attempt_can_still_succeed(): void
    {
        $this->conCodigo(attrs: ['intentos' => 4]);

        $this->reset()->assertOk();

        $this->assertTrue(Hash::check(self::PASSWORD_NUEVA, $this->cuenta->fresh()->password));
    }

    public function test_a_tenant_user_with_the_same_email_is_untouched(): void
    {
        $tenant = User::factory()->create(['email' => 'ana@x.com']);
        $this->conCodigo();

        $this->reset()->assertOk();

        $this->assertSame($tenant->password, $tenant->fresh()->password);
        $this->assertSame(0, $tenant->tokens()->count());
    }

    public function test_the_response_is_never_cached(): void
    {
        $this->conCodigo();

        $this->reset()->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    // ───────── one indistinguishable failure ─────────

    /** @return array<string, array{0: \Closure(self): array}> */
    public static function fallosIndistinguibles(): array
    {
        return [
            'unknown email' => [fn (self $t) => $t->reset(['email' => 'nadie@x.com'])],
            'wrong code' => [function (self $t) {
                $t->conCodigo();

                return $t->reset(['code' => '111111']);
            }],
            'no code was ever requested' => [fn (self $t) => $t->reset()],
            'expired code' => [function (self $t) {
                $t->conCodigo(attrs: ['expira_en' => now()->timestamp - 1]);

                return $t->reset();
            }],
            'exhausted attempts, correct code' => [function (self $t) {
                $t->conCodigo(attrs: ['intentos' => 5]);

                return $t->reset();
            }],
            'inactive account' => [function (self $t) {
                $t->conCodigo();
                $t->cuenta->forceFill(['activo' => false])->save();

                return $t->reset();
            }],
            'code of another account' => [function (self $t) {
                $otra = $t->crearCuentaPortal('otra@x.com');
                $t->conCodigo($otra, codigo: '999999');

                return $t->reset(['code' => '999999']);
            }],
            'code with the wrong format' => [function (self $t) {
                $t->conCodigo();

                return $t->reset(['code' => '48291']);
            }],
        ];
    }

    #[DataProvider('fallosIndistinguibles')]
    public function test_every_failure_has_the_same_status_body_and_headers(\Closure $escenario): void
    {
        $esperado = $this->huella($this->reset(['code' => '000000', 'email' => 'referencia@x.com'], '10.1.1.1'));

        $respuesta = $escenario($this);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertSame($esperado, $this->huella($respuesta));
        $this->assertSame(['code'], array_keys($respuesta->json('errors')));
        // None of those failures may have changed anything.
        $this->assertTrue($this->passwordSigueIgual());
    }

    public function test_every_failure_that_reaches_the_comparison_costs_exactly_one_hash_check(): void
    {
        // Fixtures first: hashing them would count as hashing done by the request.
        $hashBueno = Hash::make(self::CODIGO);
        $conteos = [];
        $casos = [
            'unknown email' => fn () => $this->reset(['email' => 'nadie@x.com']),
            'wrong code' => function () use ($hashBueno) {
                $this->conCodigo(attrs: ['code_hash' => $hashBueno]);

                return $this->reset(['code' => '111111']);
            },
            'expired code' => function () {
                DB::table(self::TABLA)->update(['expira_en' => now()->timestamp - 1]);

                return $this->reset();
            },
            'exhausted attempts' => function () {
                DB::table(self::TABLA)->update(['expira_en' => now()->timestamp + 100, 'intentos' => 5]);

                return $this->reset();
            },
            'inactive account' => function () {
                DB::table(self::TABLA)->update(['intentos' => 0]);
                $this->cuenta->forceFill(['activo' => false])->save();

                return $this->reset();
            },
        ];
        foreach ($casos as $nombre => $caso) {
            $hash = $this->contarHashes();
            $caso()->assertUnprocessable();
            $conteos[$nombre] = [$hash->comprobaciones, $hash->llamadas];
        }

        $this->assertSame(array_fill_keys(array_keys($casos), [1, 0]), $conteos);
    }

    public function test_a_success_checks_once_and_hashes_the_new_password_once(): void
    {
        $this->conCodigo();
        $hash = $this->contarHashes();

        $this->reset()->assertOk();

        $this->assertSame(1, $hash->comprobaciones);
        $this->assertSame(1, $hash->llamadas);
    }

    public function test_a_malformed_code_is_the_same_failure_without_touching_the_attempt_counter(): void
    {
        $this->conCodigo();
        $esperado = $this->huella($this->reset(['code' => '111111'], '10.1.2.1'));

        foreach (['abc123', '12345', '1234567', '12 345', '12345a', '１２３４５６', '-12345'] as $i => $formato) {
            $this->assertSame($esperado, $this->huella($this->reset(['code' => $formato], '10.1.3.'.$i)), "code {$formato}");
        }

        $this->assertSame(1, $this->intentos(), 'only the well-formed wrong code counted');
    }

    public function test_the_dummy_hash_has_the_cost_of_a_real_one(): void
    {
        $ficticio = (new \ReflectionClassConstant(RestablecerPasswordConCodigo::class, 'HASH_FICTICIO'))->getValue();

        $this->assertSame(12, password_get_info($ficticio)['options']['cost']);
        $this->assertSame('bcrypt', password_get_info($ficticio)['algoName']);
    }

    // ───────── attempts ─────────

    public function test_each_wrong_code_counts_one_attempt_in_the_database(): void
    {
        $this->conCodigo();

        foreach ([1, 2, 3] as $esperados) {
            $this->reset(['code' => '111111'], '10.1.4.'.$esperados)->assertUnprocessable();
            $this->assertSame($esperados, $this->intentos());
        }
    }

    public function test_five_wrong_codes_invalidate_the_code_even_for_the_correct_one(): void
    {
        $this->conCodigo();

        foreach (range(1, 5) as $i) {
            $this->reset(['code' => '111111'], '10.1.5.'.$i)->assertUnprocessable();
        }
        $this->reset(['code' => self::CODIGO], '10.1.5.9')->assertUnprocessable();

        $this->assertTrue($this->passwordSigueIgual());
        $this->assertSame(5, $this->intentos(), 'the exhausted row stays, it keeps the forgot cooldown');
    }

    public function test_four_wrong_codes_then_the_correct_one_succeeds(): void
    {
        $this->conCodigo();

        foreach (range(1, 4) as $i) {
            $this->reset(['code' => '111111'], '10.1.6.'.$i)->assertUnprocessable();
        }
        $this->reset(['code' => self::CODIGO], '10.1.6.9')->assertOk();
    }

    public function test_the_attempt_counter_survives_a_cache_flush(): void
    {
        $this->conCodigo();
        foreach (range(1, 3) as $i) {
            $this->reset(['code' => '111111'], '10.1.7.'.$i)->assertUnprocessable();
        }

        Cache::flush();
        Artisan::call('cache:clear');

        $this->assertSame(3, $this->intentos());
        foreach ([4, 5] as $i) {
            $this->reset(['code' => '111111'], '10.1.7.'.$i)->assertUnprocessable();
        }
        $this->reset([], '10.1.7.9')->assertUnprocessable();
        $this->assertTrue($this->passwordSigueIgual());
    }

    public function test_wrong_codes_only_burn_the_attempts_of_the_targeted_account(): void
    {
        $otra = $this->crearCuentaPortal('otra@x.com');
        $this->conCodigo();
        $this->conCodigo($otra, codigo: '777777');

        $this->reset(['code' => '111111'])->assertUnprocessable();

        $this->assertSame(1, $this->intentos());
        $this->assertSame(0, (int) $this->fila($otra)->intentos);
    }

    public function test_wrong_codes_for_an_unknown_email_create_and_change_nothing(): void
    {
        $this->reset(['email' => 'nadie@x.com', 'code' => '111111'])->assertUnprocessable();

        $this->assertSame(0, DB::table(self::TABLA)->count());
    }

    public function test_the_attempt_is_reserved_atomically_before_the_comparison(): void
    {
        $this->conCodigo();
        DB::enableQueryLog();

        $this->reset(['code' => '111111'])->assertUnprocessable();

        $reservas = array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $sql) => str_starts_with($sql, 'update "'.self::TABLA.'"'),
        ));
        $this->assertCount(1, $reservas);
        // One conditional UPDATE: it cannot be won by two parallel guesses once the cap is hit.
        $this->assertStringContainsString('"intentos" = "intentos" + 1', $reservas[0]);
        $this->assertStringContainsString('"intentos" < ?', $reservas[0]);
        $this->assertStringContainsString('"expira_en" > ?', $reservas[0]);
        $this->assertStringContainsString('"code_hash" = ?', $reservas[0]);
    }

    public function test_a_parallel_guess_that_exhausts_the_code_after_we_read_it_wins(): void
    {
        $this->conCodigo(attrs: ['intentos' => 4]);
        // Between our read of the row (intentos = 4) and our attempt, a parallel request spends the last one.
        $this->alPrimerSelectDe(self::TABLA, fn () => DB::table(self::TABLA)->update(['intentos' => 5]));

        $this->reset()->assertUnprocessable();

        $this->assertTrue($this->passwordSigueIgual(), 'the correct code must not slip past the cap');
    }

    public function test_a_code_replaced_after_we_read_it_spends_none_of_the_new_codes_attempts(): void
    {
        $this->conCodigo();
        // A new code is issued (new hash, attempts back to 0) between our read and our attempt.
        $nuevoHash = Hash::make('654321');
        $this->alPrimerSelectDe(self::TABLA, fn () => DB::table(self::TABLA)->update(['code_hash' => $nuevoHash]));

        $this->reset()->assertUnprocessable();

        $this->assertSame(0, $this->intentos());
        $this->assertTrue($this->passwordSigueIgual());
        $this->assertSame($nuevoHash, $this->fila()->code_hash, 'the new code is untouched');
    }

    // ───────── validation ─────────

    public function test_a_short_password_is_a_422_that_does_not_consume_an_attempt_nor_the_code(): void
    {
        $this->conCodigo();

        $this->reset(['password' => '123456789', 'password_confirmation' => '123456789'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame(0, $this->intentos());

        $this->reset(['password' => '1234567890', 'password_confirmation' => '1234567890'])->assertOk();
    }

    public function test_the_password_rules_are_enforced_before_the_code_is_looked_at(): void
    {
        $this->conCodigo();
        $largo = str_repeat('a', 256);

        foreach ([
            ['password' => self::PASSWORD_NUEVA, 'password_confirmation' => 'distinta-por-completo'],
            ['password' => self::PASSWORD_NUEVA, 'password_confirmation' => null],
            ['password' => $largo, 'password_confirmation' => $largo],
            ['password' => ['array-no-valido'], 'password_confirmation' => ['array-no-valido']],
        ] as $i => $malo) {
            // A wrong code with a bad password still reports the password: validation never depends on the account.
            $this->reset(array_merge($malo, ['code' => '111111']), '10.1.8.'.$i)
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }

        $this->assertSame(0, $this->intentos());
        $this->assertTrue($this->passwordSigueIgual());
    }

    public function test_missing_or_malformed_fields_are_a_422_whether_or_not_the_account_exists(): void
    {
        $this->conCodigo();

        foreach (['email', 'code', 'password'] as $campo) {
            $cuerpo = [$campo => null];
            $this->reset($cuerpo, '10.1.10.1')->assertUnprocessable()->assertJsonValidationErrors($campo);
            $this->reset($cuerpo + ['email' => 'nadie@x.com'], '10.1.10.2')->assertUnprocessable()->assertJsonValidationErrors($campo);
        }
        $this->reset(['email' => 'no-es-un-email'], '10.1.10.3')->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->reset(['code' => ['123456']], '10.1.10.4')->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertSame(0, $this->intentos());
    }

    // ───────── expiry ─────────

    public function test_the_code_works_at_29_59_and_not_at_30_00(): void
    {
        $this->conCodigo();
        $t0 = now()->timestamp;

        Carbon::setTestNow(Carbon::createFromTimestamp($t0 + 1800));
        $this->reset([], '10.1.11.1')->assertUnprocessable();
        $this->assertTrue($this->passwordSigueIgual());

        Carbon::setTestNow(Carbon::createFromTimestamp($t0 + 1799));
        $this->reset([], '10.1.11.2')->assertOk();
    }

    public function test_a_code_that_expired_does_not_consume_attempts(): void
    {
        $this->conCodigo(attrs: ['expira_en' => now()->timestamp - 5]);

        $this->reset(['code' => '111111'])->assertUnprocessable();

        $this->assertSame(0, $this->intentos());
    }

    // ───────── account state ─────────

    public function test_an_account_deactivated_after_the_code_was_issued_cannot_reset(): void
    {
        $this->conCodigo();
        $this->cuenta->forceFill(['activo' => false])->save();

        $this->reset()->assertUnprocessable();

        $this->assertTrue($this->passwordSigueIgual());
        $this->assertNotNull($this->fila());
        $this->assertSame(0, $this->intentos(), 'an inactive account takes the same path as an unknown email: no attempt spent');
    }

    public function test_resetting_does_not_reactivate_a_disabled_link_or_grant_access(): void
    {
        $this->cuenta->vinculos()->update(['activo' => false]);
        $this->conCodigo();

        $this->reset()->assertOk();

        $this->login(self::PASSWORD_NUEVA)->assertUnauthorized();
        $this->assertSame(0, $this->cuenta->vinculos()->activos()->count());
        $this->assertTrue($this->cuenta->fresh()->activo);
    }

    // ───────── races ─────────

    public function test_a_parallel_reset_that_burns_the_code_after_our_comparison_wins(): void
    {
        $this->conCodigo();
        // Right after our attempt is reserved, a parallel request with the same correct code finishes first.
        $hecho = false;
        DB::listen(function ($consulta) use (&$hecho) {
            if (! $hecho && str_starts_with($consulta->sql, 'update "'.self::TABLA.'"')) {
                $hecho = true;
                DB::table(self::TABLA)->delete();
                $this->cuenta->newQuery()->whereKey($this->cuenta->id)->update(['password' => Hash::make('la-del-otro-pedido')]);
            }
        });

        $this->reset()->assertUnprocessable();

        $this->assertTrue(Hash::check('la-del-otro-pedido', $this->cuenta->fresh()->password), 'a burned code must not be replayed');
    }

    public function test_a_new_code_issued_while_we_compare_survives_and_the_old_one_does_not_reset(): void
    {
        $this->conCodigo();
        $nuevoHash = Hash::make('654321');
        $hecho = false;
        DB::listen(function ($consulta) use (&$hecho, $nuevoHash) {
            if (! $hecho && str_starts_with($consulta->sql, 'update "'.self::TABLA.'"')) {
                $hecho = true;
                DB::table(self::TABLA)->update(['code_hash' => $nuevoHash, 'intentos' => 0]);
            }
        });

        $this->reset()->assertUnprocessable();

        $this->assertTrue($this->passwordSigueIgual());
        $this->assertNotNull($this->fila(), 'the freshly issued code is not burned by a stale reset');
        $this->assertSame($nuevoHash, $this->fila()->code_hash);
    }

    public function test_an_account_deactivated_while_we_compare_is_not_reset(): void
    {
        $this->conCodigo();
        $hecho = false;
        DB::listen(function ($consulta) use (&$hecho) {
            if (! $hecho && str_starts_with($consulta->sql, 'update "'.self::TABLA.'"')) {
                $hecho = true;
                $this->cuenta->newQuery()->whereKey($this->cuenta->id)->update(['activo' => false]);
            }
        });

        $this->reset()->assertUnprocessable();

        $this->assertTrue($this->passwordSigueIgual());
        $this->assertNotNull($this->fila(), 'the code is not burned by a refused reset');
    }

    // ───────── limits, kill switch, logs ─────────

    public function test_the_ip_throttle_is_ten_per_minute_whatever_the_email(): void
    {
        foreach (range(1, 10) as $i) {
            $this->reset(['email' => "nadie{$i}@x.com"], '10.1.12.1')->assertUnprocessable();
        }
        $this->conCodigo();

        $bloqueada = $this->reset([], '10.1.12.1');

        $bloqueada->assertStatus(429);
        $this->assertNotNull($bloqueada->headers->get('Retry-After'));
        $this->assertTrue($this->passwordSigueIgual());
        $this->reset([], '10.1.12.2')->assertOk();
    }

    public function test_the_route_is_a_404_with_the_kill_switch_off(): void
    {
        $this->conCodigo();
        config(['portal_profesionales.habilitado' => false]);

        $this->reset()->assertNotFound();

        $this->assertTrue($this->passwordSigueIgual());
        $this->assertNotNull($this->fila());
    }

    public function test_neither_the_code_nor_the_passwords_nor_the_email_are_logged(): void
    {
        $this->conCodigo();
        Log::spy();

        $this->reset(['code' => '111111'], '10.1.13.1')->assertUnprocessable();
        $this->reset([], '10.1.13.2')->assertOk();

        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $nivel) {
            Log::shouldNotHaveReceived($nivel, function ($mensaje, $contexto = []) {
                $texto = json_encode([$mensaje, $contexto]);

                return str_contains($texto, self::CODIGO) || str_contains($texto, '111111')
                    || str_contains($texto, self::PASSWORD_NUEVA) || str_contains($texto, 'clave-vieja')
                    || str_contains($texto, 'ana@x.com');
            });
        }
    }

    // ───────── the whole round trip ─────────

    public function test_forgot_then_reset_with_the_mailed_code_then_login(): void
    {
        Mail::fake();
        $this->reiniciarTareasPostRespuesta();
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.14.1'])->postJson('/api/pro/forgot-password', ['email' => 'ana@x.com'])->assertOk();
        $codigo = Mail::sent(ProfesionalResetCodeMail::class)->first()->codigo;

        $this->reset(['code' => $codigo], '10.1.14.2')->assertOk();

        $this->login(self::PASSWORD_NUEVA)->assertOk();
        $this->login('clave-vieja-1234')->assertUnauthorized();
        $this->reset(['code' => $codigo], '10.1.14.3')->assertUnprocessable();
    }

    public function test_a_new_code_requested_after_the_cooldown_invalidates_the_old_one(): void
    {
        Mail::fake();
        $this->reiniciarTareasPostRespuesta();
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.15.1'])->postJson('/api/pro/forgot-password', ['email' => 'ana@x.com'])->assertOk();
        $viejo = Mail::sent(ProfesionalResetCodeMail::class)->first()->codigo;

        Carbon::setTestNow(now()->addSeconds(61));
        $this->reiniciarTareasPostRespuesta();
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.15.2'])->postJson('/api/pro/forgot-password', ['email' => 'ana@x.com'])->assertOk();
        $nuevo = Mail::sent(ProfesionalResetCodeMail::class)->last()->codigo;
        $this->assertNotSame($viejo, $nuevo);

        $this->reset(['code' => $viejo], '10.1.15.3')->assertUnprocessable();
        $this->reset(['code' => $nuevo], '10.1.15.4')->assertOk();
    }
}

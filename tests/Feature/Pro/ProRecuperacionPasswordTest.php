<?php

namespace Tests\Feature\Pro;

use App\Mail\ProfesionalResetCodeMail;
use App\Models\Profesional;
use App\Models\ProfesionalCuenta;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * PR-01: the account holder asks for a recovery code. The security contract is
 * "nothing can be learned from the request": same status, body and headers for
 * every kind of email, same work, and the mail goes out AFTER the response.
 */
class ProRecuperacionPasswordTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private const URL = '/api/pro/forgot-password';

    private const TABLA = 'profesional_cuenta_reset_codes';

    /** @var array<string, Profesional> */
    private array $negocio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->negocio = $this->crearNegocioPortal();
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-17 12:00:00'));
    }

    private function forgot(string $email, string $ip = '10.0.0.1')
    {
        $this->reiniciarTareasPostRespuesta();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(self::URL, ['email' => $email]);
    }

    /** An account that can recover: active, with one active link to an active professional. */
    private function cuentaRecuperable(string $email = 'ana@x.com', array $attrs = []): ProfesionalCuenta
    {
        $cuenta = $this->crearCuentaPortal($email, 'password-seguro', $attrs);
        // A professional has at most one account: every account gets her own.
        $this->vincularPortal($cuenta, $this->crearProfesionalPortal($this->negocio['user'], 'P'.$cuenta->id));

        return $cuenta;
    }

    /** @return list<ProfesionalResetCodeMail> */
    private function mailsEnviados(): array
    {
        return Mail::sent(ProfesionalResetCodeMail::class)->all();
    }

    private function filaDe(ProfesionalCuenta $cuenta): ?object
    {
        return DB::table(self::TABLA)->where('cuenta_id', $cuenta->id)->first();
    }

    /** What a caller can observe: status, body and headers (minus per-request noise). */
    private function huella($respuesta): array
    {
        $cabeceras = $respuesta->headers->all();
        unset($cabeceras['date'], $cabeceras['x-ratelimit-remaining']);
        ksort($cabeceras);

        return [$respuesta->getStatusCode(), $respuesta->getContent(), $cabeceras];
    }

    // ───────── no oracle ─────────

    public function test_the_response_is_identical_for_every_kind_of_email(): void
    {
        $this->cuentaRecuperable('valida@x.com');
        $this->cuentaRecuperable('inactiva@x.com', ['activo' => false]);
        // Active account whose only link is disabled / whose professional is inactive / with no link at all.
        $sinVinculoActivo = $this->crearCuentaPortal('vinculo-off@x.com');
        $this->vincularPortal($sinVinculoActivo, $this->negocio['bea'], false);
        $profesionalInactiva = $this->crearProfesionalPortal($this->negocio['user'], 'Inactiva', false);
        $this->vincularPortal($this->crearCuentaPortal('pro-inactiva@x.com'), $profesionalInactiva);
        $this->crearCuentaPortal('sin-vinculo@x.com');
        User::factory()->create(['email' => 'solo-tenant@x.com']);

        $huellas = [];
        foreach (['valida@x.com', 'inexistente@x.com', 'inactiva@x.com', 'vinculo-off@x.com', 'pro-inactiva@x.com', 'sin-vinculo@x.com', 'solo-tenant@x.com'] as $i => $email) {
            $huellas[$email] = $this->huella($this->forgot($email, '10.0.1.'.$i));
        }

        $this->assertSame(200, $huellas['valida@x.com'][0]);
        $this->assertJson($huellas['valida@x.com'][1]);
        $this->assertNotEmpty(json_decode($huellas['valida@x.com'][1], true)['message']);
        foreach ($huellas as $email => $huella) {
            $this->assertSame($huellas['valida@x.com'], $huella, "{$email} is distinguishable from a valid account.");
        }
    }

    public function test_the_response_while_the_cooldown_applies_is_identical_too(): void
    {
        $this->cuentaRecuperable();

        $primera = $this->huella($this->forgot('ana@x.com', '10.0.2.1'));
        $segunda = $this->huella($this->forgot('ana@x.com', '10.0.2.2'));

        $this->assertSame($primera, $segunda);
        $this->assertCount(1, $this->mailsEnviados());
    }

    public function test_a_malformed_email_is_a_422_whether_or_not_such_an_account_exists(): void
    {
        $this->cuentaRecuperable('ana@x.com');

        $this->postJson(self::URL, [])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson(self::URL, ['email' => 'no-es-un-email'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson(self::URL, ['email' => ['ana@x.com']])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson(self::URL, ['email' => str_repeat('a', 250).'@x.com'])->assertUnprocessable()->assertJsonValidationErrors('email');

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table(self::TABLA)->count());
    }

    public function test_the_mail_work_runs_after_the_response_not_before(): void
    {
        $existente = $this->cuentaRecuperable('ana@x.com');
        $kernel = $this->app->make(Kernel::class);
        $this->reiniciarTareasPostRespuesta();

        $enviar = function (string $email, string $ip) use ($kernel) {
            $request = Request::create(self::URL, 'POST', ['email' => $email], [], [], [
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => $ip,
            ]);

            return [$request, $kernel->handle($request)];
        };

        [$requestA, $respuestaA] = $enviar('ana@x.com', '10.0.3.1');
        [, $respuestaB] = $enviar('inexistente@x.com', '10.0.3.2');

        // Response ready, nothing done yet: existing and unknown emails cost the same up to here.
        $this->assertSame(200, $respuestaA->getStatusCode());
        $this->assertSame($respuestaA->getContent(), $respuestaB->getContent());
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table(self::TABLA)->count());

        // One terminate runs the after-response work of both requests (and a
        // second would replay it: see reiniciarTareasPostRespuesta).
        $kernel->terminate($requestA, $respuestaA);

        $this->assertCount(1, $this->mailsEnviados());
        $this->assertNotNull($this->filaDe($existente));
        $this->assertSame(1, DB::table(self::TABLA)->count());
    }

    public function test_every_path_hashes_exactly_once(): void
    {
        $this->cuentaRecuperable('valida@x.com');
        $this->cuentaRecuperable('inactiva@x.com', ['activo' => false]);
        $this->crearCuentaPortal('sin-vinculo@x.com');

        $conteos = [];
        foreach (['valida@x.com', 'valida@x.com', 'inactiva@x.com', 'sin-vinculo@x.com', 'inexistente@x.com'] as $i => $email) {
            $hash = $this->contarHashes();
            $this->forgot($email, '10.0.4.'.$i)->assertOk();
            // The second 'valida@x.com' request lands in the cooldown.
            $conteos[] = $hash->llamadas;
        }

        $this->assertSame([1, 1, 1, 1, 1], $conteos);
    }

    // ───────── what a valid request does ─────────

    public function test_a_valid_account_gets_one_mail_and_a_hashed_code_valid_for_thirty_minutes(): void
    {
        $cuenta = $this->cuentaRecuperable();

        $this->forgot('ana@x.com')->assertOk();

        $mails = $this->mailsEnviados();
        $this->assertCount(1, $mails);
        $this->assertTrue($mails[0]->hasTo('ana@x.com'));
        $codigo = $mails[0]->codigo;
        $this->assertMatchesRegularExpression('/\A[1-9]\d{5}\z/', $codigo);

        $fila = $this->filaDe($cuenta);
        $this->assertNotNull($fila);
        $this->assertTrue(Hash::check($codigo, $fila->code_hash));
        $this->assertTrue(Hash::isHashed($fila->code_hash));
        $this->assertSame(now()->timestamp + 1800, (int) $fila->expira_en);
        $this->assertSame(now()->timestamp, (int) $fila->ultimo_envio_en);
        $this->assertSame(0, (int) $fila->intentos);
        // The plain code is stored nowhere in the row.
        $this->assertNotContains($codigo, array_map('strval', array_values((array) $fila)));
    }

    public function test_codes_are_fresh_six_digit_numbers_each_time(): void
    {
        $generados = [];
        foreach (range(1, 6) as $i) {
            $cuenta = $this->crearCuentaPortal("u{$i}@x.com");
            $this->vincularPortal($cuenta, $this->crearProfesionalPortal($this->negocio['user'], "P{$i}"));
            $this->forgot("u{$i}@x.com", '10.0.5.'.$i)->assertOk();
        }
        foreach ($this->mailsEnviados() as $mail) {
            $this->assertMatchesRegularExpression('/\A[1-9]\d{5}\z/', $mail->codigo);
            $generados[$mail->codigo] = true;
        }

        $this->assertCount(6, $this->mailsEnviados());
        $this->assertGreaterThan(1, count($generados), 'the code must not be a constant');
    }

    public function test_an_account_that_has_not_activated_yet_can_recover_too(): void
    {
        $cuenta = $this->cuentaRecuperable('nueva@x.com', ['debe_cambiar_password' => true]);

        $this->forgot('nueva@x.com')->assertOk();

        $this->assertCount(1, $this->mailsEnviados());
        $this->assertNotNull($this->filaDe($cuenta));
    }

    public function test_the_email_is_normalized_before_the_lookup(): void
    {
        $cuenta = $this->cuentaRecuperable('ana@x.com');

        $this->forgot("  ANA@X.com \t")->assertOk();

        $mails = $this->mailsEnviados();
        $this->assertCount(1, $mails);
        $this->assertTrue($mails[0]->hasTo('ana@x.com'));
        $this->assertNotNull($this->filaDe($cuenta));
    }

    public function test_the_mail_goes_only_to_the_account_email(): void
    {
        $this->cuentaRecuperable('ana@x.com');
        $this->vincularPortal($this->crearCuentaPortal('otra@x.com'), $this->negocio['bea']);

        $this->forgot('ana@x.com')->assertOk();

        $mails = $this->mailsEnviados();
        $this->assertCount(1, $mails);
        $this->assertTrue($mails[0]->hasTo('ana@x.com'));
        $this->assertFalse($mails[0]->hasTo('otra@x.com'));
        $this->assertNull(DB::table(self::TABLA)->where('cuenta_id', ProfesionalCuenta::where('email', 'otra@x.com')->value('id'))->first());
    }

    // ───────── nothing for accounts that cannot recover ─────────

    public function test_no_mail_and_no_row_for_accounts_that_cannot_recover(): void
    {
        $inactiva = $this->cuentaRecuperable('inactiva@x.com', ['activo' => false]);
        $vinculoOff = $this->crearCuentaPortal('vinculo-off@x.com');
        $this->vincularPortal($vinculoOff, $this->negocio['bea'], false);
        $sinVinculo = $this->crearCuentaPortal('sin-vinculo@x.com');
        $profesionalInactiva = $this->crearProfesionalPortal($this->negocio['user'], 'Inactiva', false);
        $proInactiva = $this->crearCuentaPortal('pro-inactiva@x.com');
        $this->vincularPortal($proInactiva, $profesionalInactiva);

        foreach (['inactiva@x.com', 'vinculo-off@x.com', 'sin-vinculo@x.com', 'pro-inactiva@x.com', 'inexistente@x.com'] as $i => $email) {
            $this->forgot($email, '10.0.6.'.$i)->assertOk();
        }

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table(self::TABLA)->count());
        $this->assertNull($this->filaDe($inactiva));
        $this->assertNull($this->filaDe($sinVinculo));
    }

    public function test_a_tenant_user_with_the_same_email_gets_nothing_and_loses_nothing(): void
    {
        $tenant = User::factory()->create(['email' => 'duena@x.com']);

        $this->forgot('duena@x.com')->assertOk();

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table(self::TABLA)->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertSame($tenant->password, $tenant->fresh()->password);
    }

    public function test_the_tenant_password_is_untouched_when_the_pro_account_shares_the_email(): void
    {
        $tenant = User::factory()->create(['email' => 'ana@x.com']);
        $this->cuentaRecuperable('ana@x.com');

        $this->forgot('ana@x.com')->assertOk();

        $this->assertCount(1, $this->mailsEnviados());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertSame($tenant->password, $tenant->fresh()->password);
    }

    // ───────── cooldown ─────────

    public function test_a_second_request_within_sixty_seconds_sends_nothing_and_changes_nothing(): void
    {
        $cuenta = $this->cuentaRecuperable();
        $this->forgot('ana@x.com', '10.0.7.1')->assertOk();
        $antes = $this->filaDe($cuenta);

        Carbon::setTestNow(now()->addSeconds(59));
        $this->forgot('ana@x.com', '10.0.7.2')->assertOk();

        $this->assertCount(1, $this->mailsEnviados());
        $this->assertEquals($antes, $this->filaDe($cuenta));
    }

    public function test_after_the_cooldown_a_new_code_replaces_the_old_one(): void
    {
        $cuenta = $this->cuentaRecuperable();
        $this->forgot('ana@x.com', '10.0.8.1')->assertOk();
        $primero = $this->mailsEnviados()[0]->codigo;
        $t0 = now()->timestamp;

        Carbon::setTestNow(now()->addSeconds(61));
        $this->forgot('ana@x.com', '10.0.8.2')->assertOk();

        $mails = $this->mailsEnviados();
        $this->assertCount(2, $mails);
        $segundo = $mails[1]->codigo;
        $fila = $this->filaDe($cuenta);
        $this->assertSame(1, DB::table(self::TABLA)->count(), 'a single live code per account');
        $this->assertTrue(Hash::check($segundo, $fila->code_hash));
        $this->assertFalse(Hash::check($primero, $fila->code_hash), 'the old code stops working');
        $this->assertSame($t0 + 61 + 1800, (int) $fila->expira_en);
        $this->assertSame($t0 + 61, (int) $fila->ultimo_envio_en);
    }

    public function test_the_cooldown_boundary_is_exactly_sixty_seconds(): void
    {
        $this->cuentaRecuperable();
        $this->forgot('ana@x.com', '10.0.9.1')->assertOk();

        Carbon::setTestNow(now()->addSeconds(59));
        $this->forgot('ana@x.com', '10.0.9.2')->assertOk();
        $this->assertCount(1, $this->mailsEnviados());

        Carbon::setTestNow(now()->addSecond()); // 60 s after the first code
        $this->forgot('ana@x.com', '10.0.9.3')->assertOk();
        $this->assertCount(2, $this->mailsEnviados());
    }

    public function test_email_case_and_spaces_share_one_cooldown_bucket(): void
    {
        $this->cuentaRecuperable();

        $this->forgot('ana@x.com', '10.0.10.1')->assertOk();
        $this->forgot('ANA@x.com', '10.0.10.2')->assertOk();
        $this->forgot('  Ana@X.COM ', '10.0.10.3')->assertOk();

        $this->assertCount(1, $this->mailsEnviados());
    }

    public function test_the_cooldown_is_per_email(): void
    {
        $this->cuentaRecuperable('ana@x.com');
        $otra = $this->crearCuentaPortal('bea@x.com');
        $this->vincularPortal($otra, $this->negocio['bea']);

        $this->forgot('ana@x.com', '10.0.11.1')->assertOk();
        $this->forgot('bea@x.com', '10.0.11.2')->assertOk();

        $this->assertCount(2, $this->mailsEnviados());
    }

    public function test_requesting_a_code_inside_the_cooldown_cannot_reset_the_attempt_counter(): void
    {
        $cuenta = $this->cuentaRecuperable();
        $this->forgot('ana@x.com', '10.0.12.1')->assertOk();
        DB::table(self::TABLA)->where('cuenta_id', $cuenta->id)->update(['intentos' => 3]);

        Carbon::setTestNow(now()->addSeconds(30));
        $this->forgot('ana@x.com', '10.0.12.2')->assertOk();

        $this->assertSame(3, (int) $this->filaDe($cuenta)->intentos);
    }

    public function test_an_exhausted_code_keeps_enforcing_the_cooldown(): void
    {
        $cuenta = $this->cuentaRecuperable();
        $this->forgot('ana@x.com', '10.0.13.1')->assertOk();
        $hash = $this->filaDe($cuenta)->code_hash;
        DB::table(self::TABLA)->where('cuenta_id', $cuenta->id)->update(['intentos' => 5]);

        // Burning the 5 attempts must not buy an immediate fresh code with 5 more guesses.
        Carbon::setTestNow(now()->addSeconds(10));
        $this->forgot('ana@x.com', '10.0.13.2')->assertOk();
        $this->assertSame($hash, $this->filaDe($cuenta)->code_hash);
        $this->assertSame(5, (int) $this->filaDe($cuenta)->intentos);
        $this->assertCount(1, $this->mailsEnviados());

        // The normal 60 s cooldown is NOT enough after an exhausted code: guessing
        // 5 codes per minute from a single IP would add up to a real takeover budget.
        Carbon::setTestNow(now()->addSeconds(70));
        $this->forgot('ana@x.com', '10.0.13.3')->assertOk();
        $this->assertSame($hash, $this->filaDe($cuenta)->code_hash);
        $this->assertSame(5, (int) $this->filaDe($cuenta)->intentos);
        $this->assertCount(1, $this->mailsEnviados());

        // One second short of an hour after that code was sent: still blocked.
        Carbon::setTestNow(Carbon::createFromTimestamp((int) $this->filaDe($cuenta)->ultimo_envio_en + 3599));
        $this->forgot('ana@x.com', '10.0.13.4')->assertOk();
        $this->assertSame($hash, $this->filaDe($cuenta)->code_hash);
        $this->assertCount(1, $this->mailsEnviados());

        // An hour after it was sent, the new code does start from zero attempts.
        Carbon::setTestNow(Carbon::createFromTimestamp((int) $this->filaDe($cuenta)->ultimo_envio_en + 3600));
        $this->forgot('ana@x.com', '10.0.13.5')->assertOk();
        $this->assertNotSame($hash, $this->filaDe($cuenta)->code_hash);
        $this->assertSame(0, (int) $this->filaDe($cuenta)->intentos);
        $this->assertCount(2, $this->mailsEnviados());
    }

    public function test_a_code_that_is_not_exhausted_still_follows_the_sixty_second_cooldown(): void
    {
        $cuenta = $this->cuentaRecuperable();
        $this->forgot('ana@x.com', '10.0.14.1')->assertOk();
        DB::table(self::TABLA)->where('cuenta_id', $cuenta->id)->update(['intentos' => 4]);

        Carbon::setTestNow(now()->addSeconds(60));
        $this->forgot('ana@x.com', '10.0.14.2')->assertOk();

        $this->assertSame(0, (int) $this->filaDe($cuenta)->intentos);
        $this->assertCount(2, $this->mailsEnviados());
    }

    // ───────── logging and failures ─────────

    public function test_the_code_and_the_email_are_never_logged(): void
    {
        Log::spy();
        $this->cuentaRecuperable();

        $this->forgot('ana@x.com')->assertOk();

        $codigo = $this->mailsEnviados()[0]->codigo;
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $nivel) {
            Log::shouldNotHaveReceived($nivel, fn ($mensaje, $contexto = []) => str_contains(json_encode([$mensaje, $contexto]), $codigo)
                || str_contains(json_encode([$mensaje, $contexto]), 'ana@x.com'));
        }
    }

    public function test_a_mail_failure_is_swallowed_logged_without_secrets_and_does_not_change_the_response(): void
    {
        $this->cuentaRecuperable();
        $respuestaOk = $this->huella($this->forgot('inexistente@x.com', '10.0.14.1'));
        Log::spy();
        $registro = $this->mailerGrabador(true);

        $respuesta = $this->forgot('ana@x.com', '10.0.14.2');

        $this->assertSame($respuestaOk, $this->huella($respuesta));
        $this->assertCount(1, $registro->mails);
        $codigo = $registro->mails[0]->codigo;
        Log::shouldHaveReceived('error')->once();
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $nivel) {
            Log::shouldNotHaveReceived($nivel, fn ($mensaje, $contexto = []) => str_contains(json_encode([$mensaje, $contexto]), $codigo)
                || str_contains(json_encode([$mensaje, $contexto]), 'ana@x.com'));
        }
    }

    public function test_the_mail_is_sent_outside_any_open_transaction(): void
    {
        $this->cuentaRecuperable();
        $registro = $this->mailerGrabador(false);
        $base = DB::transactionLevel();

        $this->forgot('ana@x.com')->assertOk();

        $this->assertSame([$base], $registro->niveles, 'the SMTP round trip must not hold a row lock');
    }

    // ───────── limits and kill switch ─────────

    public function test_the_ip_throttle_is_five_per_minute_whatever_the_email(): void
    {
        $this->cuentaRecuperable();

        foreach (range(1, 5) as $i) {
            $this->forgot("nadie{$i}@x.com", '10.0.15.1')->assertOk();
        }
        $bloqueada = $this->forgot('ana@x.com', '10.0.15.1');

        $bloqueada->assertStatus(429);
        $this->assertNotNull($bloqueada->headers->get('Retry-After'));
        Mail::assertNothingSent();
        // Another IP is unaffected, and so is the login limiter of the blocked one.
        $this->forgot('ana@x.com', '10.0.15.2')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.15.1'])
            ->postJson('/api/pro/login', ['email' => 'ana@x.com', 'password' => 'incorrecta-1'])
            ->assertUnauthorized();
    }

    public function test_the_route_is_a_404_with_the_kill_switch_off_and_nothing_is_sent(): void
    {
        $cuenta = $this->cuentaRecuperable();
        config(['portal_profesionales.habilitado' => false]);

        $this->forgot('ana@x.com')->assertNotFound();

        Mail::assertNothingSent();
        $this->assertNull($this->filaDe($cuenta));
    }

    public function test_the_response_is_never_cached(): void
    {
        $this->forgot('ana@x.com')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    // ───────── mail copy ─────────

    public function test_the_mail_copy_is_neutral_voseo_with_the_code_and_the_portal_url(): void
    {
        config(['portal_profesionales.url' => 'https://portal.ejemplo.test']);
        $this->cuentaRecuperable();
        $this->forgot('ana@x.com')->assertOk();

        $mail = $this->mailsEnviados()[0];
        $mail->assertHasSubject('Tu código para recuperar la contraseña del portal de profesionales');
        $html = $mail->render();

        $this->assertStringContainsString($mail->codigo, $html);
        $this->assertStringContainsString('https://portal.ejemplo.test', $html);
        $this->assertStringContainsString('30 minutos', $html);
        $this->assertStringContainsString('Si no lo pediste', $html);
        $this->assertDoesNotMatchRegularExpression('/sal[oó]n|due[ñn]a|combo|ticket|clienta/iu', $html);
        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}]/u', $html);
        // No tuteo verbs in the copy.
        $this->assertDoesNotMatchRegularExpression('/\b(ingresa|usa|puedes|solicitaste|tienes)\b/iu', $html);
    }
}

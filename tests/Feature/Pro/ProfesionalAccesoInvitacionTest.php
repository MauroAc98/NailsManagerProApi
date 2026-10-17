<?php

namespace Tests\Feature\Pro;

use App\Mail\ProfesionalInvitacionMail;
use App\Mail\ProfesionalVinculadaMail;
use App\Models\AdminUser;
use App\Models\Profesional;
use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PasswordProvisoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\HashManager;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * AM-01..AM-03, PA-12 S3 (owner side): the persona titular invites one of HER
 * professionals to the portal. Security-critical: the response must be the
 * same whether or not the email already has an account (no enumeration), the
 * provisional password only ever travels by email, and linking an existing
 * account must not touch it.
 */
class ProfesionalAccesoInvitacionTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private const MENSAJE_OK = 'Invitación enviada. La persona invitada va a recibir un email con los pasos para ingresar.';

    private const EMAIL_TITULAR = 'titular@negocio.test';

    /** @var array{user: User, titular: Profesional, ana: Profesional, bea: Profesional} */
    private array $negocio;

    private string $tokenTitular;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->negocio = $this->crearNegocioPortal(['email' => self::EMAIL_TITULAR, 'name' => 'Estudio Aurora']);
        $this->tokenTitular = $this->negocio['user']->createToken('test')->plainTextToken;
    }

    private function invitar(int|string $profesionalId, mixed $email, ?string $token = null, bool $conEmail = true)
    {
        // Sanctum's guard caches the first resolved user per app instance.
        $this->app['auth']->forgetGuards();

        $cuerpo = $conEmail ? ['email' => $email] : [];

        return $this->withToken($token ?? $this->tokenTitular)
            ->postJson("/api/profesionales/{$profesionalId}/acceso", $cuerpo);
    }

    /** @return array<string, mixed>|null */
    private function filaCuenta(int $id): ?array
    {
        $fila = DB::table('profesional_cuentas')->where('id', $id)->first();

        return $fila ? (array) $fila : null;
    }

    /** Swaps the hash manager for one that counts make() calls and delegates. */
    private function contarHashes(): HashManager
    {
        $contador = new class($this->app) extends HashManager
        {
            public int $llamadas = 0;

            public function make($value, array $options = [])
            {
                $this->llamadas++;

                return parent::make($value, $options);
            }
        };
        $this->app->instance('hash', $contador);
        Hash::clearResolvedInstance('hash');

        return $contador;
    }

    /**
     * Runs $competidor once, right after the first SELECT on $tabla: the
     * moment another request could slip in between our lookup and our insert.
     * The row it writes lives outside our savepoint, like a committed row would.
     */
    private function alPrimerSelectDe(string $tabla, \Closure $competidor): void
    {
        $hecho = false;
        DB::listen(function ($consulta) use (&$hecho, $tabla, $competidor) {
            if (! $hecho && str_starts_with($consulta->sql, 'select') && str_contains($consulta->sql, '"'.$tabla.'"')) {
                $hecho = true;
                $competidor();
            }
        });
    }

    // ── AM-01 S1: unknown email creates the account and the link ───────────

    public function test_unknown_email_creates_a_provisional_account_and_an_active_link(): void
    {
        $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')
            ->assertOk()
            ->assertExactJson(['message' => self::MENSAJE_OK]);

        $cuenta = ProfesionalCuenta::where('email', 'ana.nueva@example.com')->sole();
        $this->assertTrue($cuenta->activo);
        $this->assertTrue($cuenta->debe_cambiar_password);
        $this->assertNull($cuenta->ultimo_login_at);

        $vinculo = ProfesionalVinculo::sole();
        $this->assertSame($cuenta->id, $vinculo->cuenta_id);
        $this->assertSame($this->negocio['ana']->id, $vinculo->profesional_id);
        $this->assertTrue($vinculo->activo);
        $this->assertSame($this->negocio['user']->id, $vinculo->invitado_por_user_id);
        $this->assertSame(1, ProfesionalCuenta::count());
    }

    public function test_the_stored_password_is_a_hash_and_never_the_email_or_the_name(): void
    {
        $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')->assertOk();

        $almacenada = ProfesionalCuenta::sole()->getRawOriginal('password');

        $this->assertTrue(password_get_info($almacenada)['algoName'] === 'bcrypt');
        foreach (['ana.nueva@example.com', 'ana.nueva', 'Ana', 'Estudio Aurora'] as $derivada) {
            $this->assertFalse(Hash::check($derivada, $almacenada), "The password must not be derived from {$derivada}");
        }
    }

    // ── AM-01 S2/S7 + decision #1044: an existing account is linked directly ─

    public function test_existing_account_only_gets_a_link_and_its_row_is_left_untouched(): void
    {
        $existente = $this->crearCuentaPortal('bea@example.com', 'password-de-bea');
        $this->vincularPortal($existente, $this->crearProfesionalPortal(User::factory()->create(['is_exempt' => true]), 'Otra'));
        $existente->createToken('pro-portal', ['pro:acceso']);
        $existente->createToken('pro-portal', ['pro:acceso']);
        $antes = $this->filaCuenta($existente->id);

        $this->invitar($this->negocio['bea']->id, 'bea@example.com')
            ->assertOk()
            ->assertExactJson(['message' => self::MENSAJE_OK]);

        $this->assertSame($antes, $this->filaCuenta($existente->id), 'The account row (hash, flag, timestamps) must not change');
        $this->assertSame(2, $existente->tokens()->count(), 'Live tokens must survive');
        $this->assertSame(1, ProfesionalCuenta::count());
        $this->assertSame(2, $existente->vinculos()->count());
        $nuevo = $existente->vinculos()->where('profesional_id', $this->negocio['bea']->id)->sole();
        $this->assertTrue($nuevo->activo);
        $this->assertSame($this->negocio['user']->id, $nuevo->invitado_por_user_id);
    }

    public function test_an_existing_account_can_use_the_new_link_immediately_with_its_current_token(): void
    {
        $existente = $this->crearCuentaPortal('bea@example.com');
        $tokenActual = $this->tokenPara($existente);
        $this->crearTurnoPortal($this->negocio['bea'], '2026-10-17 10:00:00');

        $this->invitar($this->negocio['bea']->id, 'bea@example.com')->assertOk();

        $vinculo = ProfesionalVinculo::where('profesional_id', $this->negocio['bea']->id)->sole();
        $this->app['auth']->forgetGuards();
        $respuesta = $this->withToken($tokenActual)
            ->withHeader('X-Vinculo-Id', (string) $vinculo->id)
            ->getJson('/api/pro/turnos?fecha=2026-10-17')
            ->assertOk();
        $this->assertCount(1, $respuesta->json('data'));

        $this->app['auth']->forgetGuards();
        $me = $this->withToken($tokenActual)->getJson('/api/pro/me')->assertOk();
        $this->assertSame([$vinculo->id], array_column($me->json('vinculos'), 'id'));
    }

    public function test_an_inactive_existing_account_is_linked_with_the_same_response_and_stays_inactive(): void
    {
        $existente = $this->crearCuentaPortal('bea@example.com', attrs: ['activo' => false]);

        $this->invitar($this->negocio['bea']->id, 'bea@example.com')
            ->assertOk()
            ->assertExactJson(['message' => self::MENSAJE_OK]);

        $this->assertFalse($existente->fresh()->activo);
        $this->assertSame(1, $existente->vinculos()->count());
    }

    // ── AM-01: no enumeration ───────────────────────────────────────────────

    public function test_the_response_is_byte_identical_for_a_new_and_an_existing_email(): void
    {
        $this->crearCuentaPortal('existente@example.com');

        $nuevo = $this->invitar($this->negocio['ana']->id, 'desconocido@example.com');
        $existente = $this->invitar($this->negocio['bea']->id, 'existente@example.com');

        $this->assertSame(200, $nuevo->getStatusCode());
        $this->assertSame($nuevo->getStatusCode(), $existente->getStatusCode());
        $this->assertSame($nuevo->getContent(), $existente->getContent());
        $this->assertSame(
            json_encode(['message' => self::MENSAJE_OK], JSON_UNESCAPED_UNICODE),
            json_encode($nuevo->json(), JSON_UNESCAPED_UNICODE),
        );
        $this->assertSame($nuevo->headers->get('Cache-Control'), $existente->headers->get('Cache-Control'));
    }

    public function test_the_response_never_carries_the_password_or_any_account_data(): void
    {
        $existente = $this->crearCuentaPortal('existente@example.com');

        foreach ([['ana', 'desconocido@example.com'], ['bea', 'existente@example.com']] as [$quien, $email]) {
            $cuerpo = $this->invitar($this->negocio[$quien]->id, $email)->assertOk()->getContent();
            $json = json_decode($cuerpo, true);

            $this->assertSame(['message'], array_keys($json));
            $this->assertStringNotContainsString($email, $cuerpo);
            $this->assertStringNotContainsStringIgnoringCase('password', $cuerpo);
            $this->assertStringNotContainsString('cuenta', $cuerpo);
            $this->assertStringNotContainsString((string) $existente->getRawOriginal('password'), $cuerpo);
        }
    }

    public function test_a_new_and_an_existing_email_each_run_exactly_one_password_hash(): void
    {
        // Timing parity: the (expensive) hash is computed whether or not the
        // email already has an account.
        $this->crearCuentaPortal('existente@example.com');
        $contador = $this->contarHashes();

        $this->invitar($this->negocio['ana']->id, 'desconocido@example.com')->assertOk();
        $this->assertSame(1, $contador->llamadas, 'A new email hashes exactly once');

        $contador->llamadas = 0;
        $this->invitar($this->negocio['bea']->id, 'existente@example.com')->assertOk();
        $this->assertSame(1, $contador->llamadas, 'An existing email hashes exactly once too');
    }

    // ── AM-01 S3: scoping, and the foreign professional gives the same 404 ─

    public function test_a_professional_of_another_negocio_and_a_nonexistent_one_give_the_same_404(): void
    {
        $otro = $this->crearNegocioPortal();

        $ajena = $this->invitar($otro['ana']->id, 'x@example.com');
        $inexistente = $this->invitar(999999, 'x@example.com');
        $malformada = $this->invitar('abc', 'x@example.com');
        $ceroDelante = $this->invitar('0'.$otro['ana']->id, 'x@example.com');

        foreach ([$ajena, $inexistente, $malformada, $ceroDelante] as $respuesta) {
            $respuesta->assertNotFound();
            $this->assertSame($ajena->getContent(), $respuesta->getContent());
        }
        $this->assertSame('PROFESIONAL_NO_ENCONTRADA', $ajena->json('code'));
        $this->assertSame(0, ProfesionalCuenta::count());
        $this->assertSame(0, ProfesionalVinculo::count());
        Mail::assertNothingSent();
    }

    public function test_an_id_with_anything_after_the_digits_is_not_accepted_for_her_own_professional(): void
    {
        // "5\n" would pass a "$"-anchored regex and then cast to 5.
        foreach (['%0A', '%20', 'abc', '.0', 'e0'] as $sufijo) {
            $this->invitar($this->negocio['ana']->id.$sufijo, 'ana.nueva@example.com')
                ->assertNotFound()
                ->assertJsonPath('code', 'PROFESIONAL_NO_ENCONTRADA');
        }

        // Nor a leading zero ("05" would cast to her own id 5).
        $this->invitar('0'.$this->negocio['ana']->id, 'ana.nueva@example.com')
            ->assertNotFound()
            ->assertJsonPath('code', 'PROFESIONAL_NO_ENCONTRADA');

        $this->assertSame(0, ProfesionalVinculo::count());
        Mail::assertNothingSent();
    }

    public function test_the_404_is_returned_before_the_email_is_validated(): void
    {
        $otro = $this->crearNegocioPortal();

        $ajenaSinEmail = $this->invitar($otro['ana']->id, null, conEmail: false);
        $inexistenteSinEmail = $this->invitar(999999, null, conEmail: false);

        $ajenaSinEmail->assertNotFound();
        $this->assertSame($ajenaSinEmail->getContent(), $inexistenteSinEmail->getContent());
    }

    // ── AM-01 S4: already linked ────────────────────────────────────────────

    public function test_a_professional_that_already_has_a_link_cannot_be_invited_with_another_email(): void
    {
        $primera = $this->crearCuentaPortal('primera@example.com');
        $this->vincularPortal($primera, $this->negocio['ana']);

        $this->invitar($this->negocio['ana']->id, 'segunda@example.com')
            ->assertStatus(409)
            ->assertJsonPath('code', 'PROFESIONAL_CON_ACCESO');

        $this->assertSame(1, ProfesionalCuenta::count(), 'No account may be created for the rejected email');
        $this->assertSame(1, ProfesionalVinculo::count());
        $this->assertSame($primera->id, ProfesionalVinculo::sole()->cuenta_id);
        Mail::assertNothingSent();
    }

    public function test_the_409_also_applies_when_the_existing_link_is_disabled_and_when_the_other_email_has_an_account(): void
    {
        $primera = $this->crearCuentaPortal('primera@example.com');
        $this->vincularPortal($primera, $this->negocio['ana'], activo: false);
        $this->crearCuentaPortal('segunda@example.com');

        $this->invitar($this->negocio['ana']->id, 'segunda@example.com')
            ->assertStatus(409)
            ->assertJsonPath('code', 'PROFESIONAL_CON_ACCESO');

        $this->assertSame(1, ProfesionalVinculo::count());
        $this->assertFalse(ProfesionalVinculo::sole()->activo);
    }

    public function test_inviting_the_same_email_again_is_idempotent_with_the_same_response(): void
    {
        $primera = $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')->assertOk();
        $hash = ProfesionalCuenta::sole()->getRawOriginal('password');

        $segunda = $this->invitar($this->negocio['ana']->id, '  ANA.NUEVA@example.com ')->assertOk();

        $this->assertSame($primera->getContent(), $segunda->getContent());
        $this->assertSame(1, ProfesionalCuenta::count());
        $this->assertSame(1, ProfesionalVinculo::count());
        $this->assertSame($hash, ProfesionalCuenta::sole()->getRawOriginal('password'));
    }

    public function test_re_inviting_does_not_re_enable_a_link_the_titular_disabled(): void
    {
        $cuenta = $this->crearCuentaPortal('ana@example.com');
        $this->vincularPortal($cuenta, $this->negocio['ana'], activo: false);

        $this->invitar($this->negocio['ana']->id, 'ana@example.com')->assertOk();

        $this->assertFalse(ProfesionalVinculo::sole()->activo);
    }

    // ── AM-01: inactive professional ────────────────────────────────────────

    public function test_an_inactive_professional_cannot_be_invited(): void
    {
        $this->negocio['ana']->update(['activo' => false]);

        $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROFESIONAL_INACTIVA');

        $this->assertSame(0, ProfesionalCuenta::count());
        $this->assertSame(0, ProfesionalVinculo::count());
        Mail::assertNothingSent();
    }

    // ── AM-01 S5/S6: normalisation and validation ──────────────────────────

    public function test_the_email_is_trimmed_and_lowercased(): void
    {
        $this->invitar($this->negocio['ana']->id, "  ANA.Nueva@Example.COM \t")->assertOk();

        $this->assertSame('ana.nueva@example.com', ProfesionalCuenta::sole()->email);
    }

    public function test_an_uppercase_email_links_the_existing_lowercase_account_instead_of_duplicating_it(): void
    {
        $existente = $this->crearCuentaPortal('bea@example.com');

        $this->invitar($this->negocio['bea']->id, 'BEA@EXAMPLE.COM')->assertOk();

        $this->assertSame(1, ProfesionalCuenta::count());
        $this->assertSame($existente->id, ProfesionalVinculo::sole()->cuenta_id);
    }

    public static function emailsInvalidos(): array
    {
        return [
            'missing' => [null, false],
            'empty' => ['', true],
            'not an email' => ['no-es-un-email', true],
            'array' => [['a@b.com'], true],
            'number' => [12345, true],
            'too long' => [str_repeat('a', 250).'@example.com', true],
        ];
    }

    #[DataProvider('emailsInvalidos')]
    public function test_an_invalid_email_is_rejected_with_422_and_nothing_is_created(mixed $email, bool $conEmail): void
    {
        $this->invitar($this->negocio['ana']->id, $email, conEmail: $conEmail)
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(0, ProfesionalCuenta::count());
        $this->assertSame(0, ProfesionalVinculo::count());
        Mail::assertNothingSent();
    }

    // ── AM-01 S1/S2: mails (6a.3) ───────────────────────────────────────────

    private function correoEnviado(string $clase): Mailable
    {
        $enviados = Mail::sent($clase);
        $this->assertCount(1, $enviados, "Exactly one {$clase} must be sent");

        return $enviados->first();
    }

    public function test_a_new_account_gets_the_invitation_mail_with_the_provisional_password(): void
    {
        $this->invitar($this->negocio['ana']->id, '  Ana.Nueva@Example.com ')->assertOk();

        $correo = $this->correoEnviado(ProfesionalInvitacionMail::class);
        $this->assertTrue($correo->hasTo('ana.nueva@example.com'));
        Mail::assertSentCount(1);

        $this->assertSame('Estudio Aurora', $correo->negocio);
        $this->assertSame('ana.nueva@example.com', $correo->email);
        $this->assertSame(config('portal_profesionales.url'), $correo->portalUrl);
        $this->assertMatchesRegularExpression('/\A['.PasswordProvisoria::ALFABETO.']{12}\z/', $correo->password);
        $this->assertTrue(
            Hash::check($correo->password, ProfesionalCuenta::sole()->getRawOriginal('password')),
            'The emailed password must be the one stored (hashed) on the account'
        );
    }

    public function test_the_emailed_password_logs_in_through_the_forced_change_flow(): void
    {
        $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')->assertOk();
        $password = $this->correoEnviado(ProfesionalInvitacionMail::class)->password;

        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/pro/login', ['email' => 'ana.nueva@example.com', 'password' => $password])
            ->assertOk()
            ->assertJsonPath('debe_cambiar_password', true);

        $this->assertSame(['pro:cambiar-password'], PersonalAccessToken::findToken($login->json('token'))->abilities);
    }

    public function test_two_new_accounts_never_get_the_same_provisional_password(): void
    {
        $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')->assertOk();
        $this->invitar($this->negocio['bea']->id, 'bea.nueva@example.com')->assertOk();

        $passwords = Mail::sent(ProfesionalInvitacionMail::class)->map(fn ($c) => $c->password);
        $this->assertCount(2, $passwords);
        $this->assertCount(2, $passwords->unique());
    }

    public function test_the_same_email_gets_a_different_password_each_time_the_account_is_created(): void
    {
        // Not derived from the email: delete the account and invite again.
        $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')->assertOk();
        ProfesionalVinculo::query()->delete();
        ProfesionalCuenta::query()->delete();
        $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')->assertOk();

        $passwords = Mail::sent(ProfesionalInvitacionMail::class)->map(fn ($c) => $c->password);
        $this->assertCount(2, $passwords);
        $this->assertCount(2, $passwords->unique());
    }

    public function test_an_existing_account_gets_the_linked_mail_and_no_password_at_all(): void
    {
        $this->crearCuentaPortal('bea@example.com', 'password-de-bea');

        $this->invitar($this->negocio['bea']->id, 'bea@example.com')->assertOk();

        Mail::assertNotSent(ProfesionalInvitacionMail::class);
        $correo = $this->correoEnviado(ProfesionalVinculadaMail::class);
        Mail::assertSentCount(1);
        $this->assertTrue($correo->hasTo('bea@example.com'));
        $this->assertSame('Estudio Aurora', $correo->negocio);
        $this->assertSame(config('portal_profesionales.url'), $correo->portalUrl);
        foreach (['password', 'contrasena', 'clave'] as $propiedad) {
            $this->assertFalse(property_exists($correo, $propiedad), "The linked mail must not carry a {$propiedad}");
        }

        $html = $correo->render();
        $this->assertStringNotContainsString('password-de-bea', $html);
        $this->assertStringNotContainsStringIgnoringCase('provisoria', $html);
    }

    public function test_the_mail_copy_is_neutral_spanish_with_the_agreed_vocabulary(): void
    {
        $this->crearCuentaPortal('bea@example.com');
        $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')->assertOk();
        $this->invitar($this->negocio['bea']->id, 'bea@example.com')->assertOk();

        $invitacion = $this->correoEnviado(ProfesionalInvitacionMail::class);
        $vinculada = $this->correoEnviado(ProfesionalVinculadaMail::class);

        $invitacion->assertHasSubject('Te dieron acceso al portal de profesionales de Estudio Aurora');
        $vinculada->assertHasSubject('Te dieron acceso al portal de profesionales de Estudio Aurora');
        foreach ([$invitacion->render(), $vinculada->render()] as $html) {
            $this->assertStringContainsString('La persona titular de Estudio Aurora te invitó a ver tu agenda', $html);
            $this->assertStringContainsString(config('portal_profesionales.url'), $html);
            foreach (['salón', 'salon', 'dueña', 'duena', 'combo', 'ticket', 'clienta'] as $prohibida) {
                $this->assertStringNotContainsStringIgnoringCase($prohibida, $html);
            }
        }
        $this->assertStringContainsString('ana.nueva@example.com', $invitacion->render());
        $this->assertStringContainsString($invitacion->password, $invitacion->render());
    }

    public function test_the_mail_copy_uses_the_same_voseo_as_the_rest_of_the_app(): void
    {
        $this->crearCuentaPortal('bea@example.com');
        $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')->assertOk();
        $this->invitar($this->negocio['bea']->id, 'bea@example.com')->assertOk();

        $invitacion = $this->correoEnviado(ProfesionalInvitacionMail::class)->render();
        $vinculada = $this->correoEnviado(ProfesionalVinculadaMail::class)->render();

        $this->assertStringContainsString('Ingresá por primera vez', $invitacion);
        $this->assertStringContainsString('podés ignorarlo', $invitacion);
        $this->assertStringContainsString('Ya tenés una cuenta', $vinculada);
        $this->assertStringContainsString('ingresá con tu email', $vinculada);
        $this->assertStringContainsString('podés ignorarlo', $vinculada);

        foreach ([$invitacion, $vinculada] as $html) {
            foreach (['Ingresa ', 'puedes', 'tienes', 'ingresa con'] as $tuteo) {
                $this->assertStringNotContainsString($tuteo, $html);
            }
        }
    }

    public function test_the_negocio_name_is_escaped_in_the_body_and_cannot_break_the_subject_line(): void
    {
        $this->negocio['user']->update(['name' => "<b>Aurora</b>\r\nBcc: espia@example.com"]);
        $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')->assertOk();

        $correo = $this->correoEnviado(ProfesionalInvitacionMail::class);

        $this->assertStringNotContainsString('<b>Aurora</b>', $correo->render());
        $this->assertStringContainsString('&lt;b&gt;Aurora&lt;/b&gt;', $correo->render());
        $asunto = $correo->envelope()->subject;
        $this->assertStringNotContainsString("\n", $asunto);
        $this->assertStringNotContainsString("\r", $asunto);
    }

    public function test_re_inviting_the_same_email_sends_no_second_mail(): void
    {
        $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')->assertOk();
        $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')->assertOk();

        Mail::assertSentCount(1);
    }

    public function test_no_mail_is_sent_for_any_rejection(): void
    {
        $otro = $this->crearNegocioPortal();
        $this->vincularPortal($this->crearCuentaPortal('primera@example.com'), $this->negocio['bea']);
        $this->negocio['ana']->update(['activo' => false]);

        $this->invitar($otro['ana']->id, 'x@example.com')->assertNotFound();
        $this->invitar($this->negocio['bea']->id, 'otra@example.com')->assertStatus(409);
        $this->invitar($this->negocio['ana']->id, 'x@example.com')->assertStatus(422);
        $this->invitar($this->negocio['ana']->id, 'no-es-un-email')->assertStatus(422);

        Mail::assertNothingSent();
    }

    /**
     * Replaces the mailer with one that records each mailable together with
     * the transaction depth at the moment of sending, and optionally fails.
     *
     * @return \stdClass{mails: list<object>, niveles: list<int>}
     */
    private function mailerGrabador(bool $falla): \stdClass
    {
        $registro = new \stdClass;
        $registro->mails = [];
        $registro->niveles = [];

        Mail::shouldReceive('to')->andReturnUsing(fn () => new class($registro, $falla)
        {
            public function __construct(private \stdClass $registro, private bool $falla) {}

            public function send($mailable): void
            {
                $this->registro->mails[] = $mailable;
                $this->registro->niveles[] = DB::transactionLevel();
                if ($this->falla) {
                    throw new \RuntimeException('smtp caido');
                }
            }
        });

        return $registro;
    }

    public function test_the_mail_is_sent_after_the_transaction_has_committed(): void
    {
        $this->crearCuentaPortal('bea@example.com');
        $nivelBase = DB::transactionLevel();
        $registro = $this->mailerGrabador(falla: false);

        $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')->assertOk();
        $this->invitar($this->negocio['bea']->id, 'bea@example.com')->assertOk();

        $this->assertCount(2, $registro->mails);
        $this->assertSame([$nivelBase, $nivelBase], $registro->niveles, 'Mail::send must run outside the invitation transaction');
    }

    public function test_a_mail_failure_keeps_the_invitation_and_logs_neither_the_password_nor_the_email(): void
    {
        $registro = $this->mailerGrabador(falla: true);
        Log::spy();

        $respuesta = $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')
            ->assertOk()
            ->assertExactJson(['message' => self::MENSAJE_OK]);

        $this->assertSame(1, ProfesionalCuenta::count(), 'The account must survive a mail failure');
        $this->assertSame(1, ProfesionalVinculo::count());
        $this->assertCount(1, $registro->mails);
        $password = $registro->mails[0]->password;
        $this->assertNotSame('', $password);
        $this->assertStringNotContainsString($password, $respuesta->getContent());

        Log::shouldHaveReceived('error')->once();
        foreach (['error', 'warning', 'info', 'debug'] as $nivel) {
            Log::shouldNotHaveReceived($nivel, fn ($mensaje, $contexto = []) => str_contains(
                json_encode([$mensaje, $contexto]),
                $password
            ) || str_contains(json_encode([$mensaje, $contexto]), 'ana.nueva@example.com'));
        }
    }

    public function test_a_mail_failure_gives_the_same_response_for_a_new_and_an_existing_email(): void
    {
        $this->crearCuentaPortal('bea@example.com');
        $this->mailerGrabador(falla: true);

        $nuevo = $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com');
        $existente = $this->invitar($this->negocio['bea']->id, 'bea@example.com');

        $this->assertSame(200, $nuevo->getStatusCode());
        $this->assertSame($nuevo->getContent(), $existente->getContent());
    }

    // ── AM-02: the titular's own profile and her own email (6a.4) ──────────

    public function test_the_titular_profile_cannot_be_invited_and_nothing_is_created_or_sent(): void
    {
        $this->invitar($this->negocio['titular']->id, 'otra.persona@example.com')
            ->assertStatus(422)
            ->assertJsonPath('code', 'PERFIL_DE_LA_PERSONA_TITULAR');

        $this->assertSame(0, ProfesionalCuenta::count());
        $this->assertSame(0, ProfesionalVinculo::count());
        Mail::assertNothingSent();
    }

    public function test_the_titular_profile_is_rejected_even_for_an_email_that_already_has_an_account_or_when_deactivated(): void
    {
        $this->crearCuentaPortal('existente@example.com');
        $this->negocio['titular']->update(['activo' => false]);

        $this->invitar($this->negocio['titular']->id, 'existente@example.com')
            ->assertStatus(422)
            ->assertJsonPath('code', 'PERFIL_DE_LA_PERSONA_TITULAR');

        $this->assertSame(0, ProfesionalVinculo::count());
        Mail::assertNothingSent();
    }

    public function test_only_the_lowest_id_professional_is_the_titular_profile(): void
    {
        // A professional of the negocio that is NOT the first one is invitable.
        $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')->assertOk();
        $this->invitar($this->negocio['bea']->id, 'bea.nueva@example.com')->assertOk();

        $this->assertSame(2, ProfesionalVinculo::count());
    }

    public static function variantesDelEmailDelTitular(): array
    {
        return [
            'exact' => [self::EMAIL_TITULAR],
            'uppercase' => ['TITULAR@NEGOCIO.TEST'],
            'padded and mixed case' => ["  Titular@Negocio.Test \t"],
        ];
    }

    #[DataProvider('variantesDelEmailDelTitular')]
    public function test_the_email_of_the_negocio_owner_is_rejected_in_any_case(string $email): void
    {
        $this->invitar($this->negocio['ana']->id, $email)
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMAIL_DE_LA_PERSONA_TITULAR');

        $this->assertSame(0, ProfesionalCuenta::count());
        $this->assertSame(0, ProfesionalVinculo::count());
        Mail::assertNothingSent();
    }

    public function test_the_owner_email_is_compared_case_insensitively_against_what_is_stored_too(): void
    {
        // users.email may have been stored with uppercase letters.
        DB::table('users')->where('id', $this->negocio['user']->id)->update(['email' => '  Titular@Negocio.TEST ']);

        $this->invitar($this->negocio['ana']->id, 'titular@negocio.test')
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMAIL_DE_LA_PERSONA_TITULAR');
        $this->assertSame(0, ProfesionalVinculo::count());
    }

    public function test_the_owner_email_is_rejected_the_same_way_whether_or_not_an_account_exists_for_it(): void
    {
        $sinCuenta = $this->invitar($this->negocio['ana']->id, self::EMAIL_TITULAR);
        $this->crearCuentaPortal(self::EMAIL_TITULAR);
        $conCuenta = $this->invitar($this->negocio['bea']->id, self::EMAIL_TITULAR);

        $sinCuenta->assertStatus(422);
        $this->assertSame($sinCuenta->getContent(), $conCuenta->getContent());
        $this->assertSame(0, ProfesionalVinculo::count(), 'The owner\'s own account must not be linked either');
    }

    public function test_the_email_of_another_negocios_owner_is_allowed(): void
    {
        User::factory()->create(['email' => 'otra.titular@example.com', 'is_exempt' => true]);
        $this->crearCuentaPortal('otra.titular@example.com');

        $this->invitar($this->negocio['ana']->id, 'otra.titular@example.com')->assertOk();
        $this->invitar($this->negocio['bea']->id, 'OTRA.TITULAR@example.com')->assertStatus(200);

        $this->assertSame(2, ProfesionalVinculo::count());
        $this->assertSame(1, ProfesionalCuenta::count());
        Mail::assertSentCount(2);
    }

    public function test_the_titular_profile_rule_comes_before_the_owner_email_rule(): void
    {
        $this->invitar($this->negocio['titular']->id, self::EMAIL_TITULAR)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PERFIL_DE_LA_PERSONA_TITULAR');
    }

    // ── AM-08 basics: only a tenant session with a valid subscription ──────

    public function test_without_a_token_the_endpoint_answers_401(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/profesionales/{$this->negocio['ana']->id}/acceso", ['email' => 'x@example.com'])
            ->assertUnauthorized();

        $this->assertSame(0, ProfesionalCuenta::count());
    }

    public function test_a_professional_portal_token_is_rejected_with_401(): void
    {
        $cuenta = $this->crearCuentaPortal('pro@example.com');
        $this->vincularPortal($cuenta, $this->negocio['bea']);

        $this->invitar($this->negocio['ana']->id, 'x@example.com', $this->tokenPara($cuenta))->assertUnauthorized();

        $this->assertSame(1, ProfesionalCuenta::count());
        Mail::assertNothingSent();
    }

    public function test_an_admin_token_is_rejected_with_401(): void
    {
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'admin@turnetto.test', 'password' => 'password-de-admin']);

        $this->invitar($this->negocio['ana']->id, 'x@example.com', $admin->createToken('admin')->plainTextToken)->assertUnauthorized();

        $this->assertSame(0, ProfesionalCuenta::count());
    }

    public function test_an_expired_subscription_blocks_the_invitation_with_the_tenant_code(): void
    {
        $vencido = $this->crearNegocioPortal(['is_exempt' => false]);
        Subscription::create(['user_id' => $vencido['user']->id, 'ends_at' => now()->subDay(), 'status' => 'ACTIVO']);
        $token = $vencido['user']->createToken('test')->plainTextToken;

        $this->invitar($vencido['ana']->id, 'x@example.com', $token)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_EXPIRED');

        $this->assertSame(0, ProfesionalCuenta::count());
        Mail::assertNothingSent();
    }

    // ── AM-03 + PA-12 S3: rate limit and kill switch (6a.5) ────────────────

    /** @return list<Profesional> ten more invitable professionals of the negocio */
    private function profesionalesExtra(int $cantidad): array
    {
        return array_map(
            fn (int $i) => $this->crearProfesionalPortal($this->negocio['user'], "Extra {$i}"),
            range(1, $cantidad),
        );
    }

    public function test_the_eleventh_invitation_in_an_hour_is_rate_limited_and_does_nothing(): void
    {
        $extra = $this->profesionalesExtra(11);
        for ($i = 0; $i < 10; $i++) {
            $this->invitar($extra[$i]->id, "persona{$i}@example.com")->assertOk();
        }

        $limitada = $this->invitar($extra[10]->id, 'persona10@example.com');

        $limitada->assertStatus(429);
        $this->assertSame('INVITACIONES_LIMITE', $limitada->json('code'));
        $this->assertNotNull($limitada->headers->get('Retry-After'));
        $this->assertSame(10, ProfesionalVinculo::count());
        $this->assertNull(ProfesionalCuenta::where('email', 'persona10@example.com')->first());
        Mail::assertSentCount(10);
    }

    public function test_the_limit_counts_invitations_to_existing_and_unknown_emails_alike(): void
    {
        $extra = $this->profesionalesExtra(11);
        for ($i = 0; $i < 5; $i++) {
            $this->crearCuentaPortal("conocida{$i}@example.com");
        }
        for ($i = 0; $i < 10; $i++) {
            $email = $i < 5 ? "conocida{$i}@example.com" : "desconocida{$i}@example.com";
            $this->invitar($extra[$i]->id, $email)->assertOk();
        }

        $this->invitar($extra[10]->id, 'conocida0@example.com')->assertStatus(429);
        $this->invitar($extra[10]->id, 'desconocida9@example.com')->assertStatus(429);
    }

    public function test_rejected_attempts_also_consume_the_limit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->invitar($this->negocio['titular']->id, "x{$i}@example.com")->assertStatus(422);
        }

        $this->invitar($this->negocio['ana']->id, 'valida@example.com')->assertStatus(429);
    }

    public function test_another_titular_is_not_affected_by_the_limit_of_this_one(): void
    {
        $extra = $this->profesionalesExtra(10);
        foreach ($extra as $i => $profesional) {
            $this->invitar($profesional->id, "persona{$i}@example.com")->assertOk();
        }
        $this->invitar($this->negocio['ana']->id, 'otra@example.com')->assertStatus(429);

        $otro = $this->crearNegocioPortal();
        $tokenOtro = $otro['user']->createToken('test')->plainTextToken;

        $this->invitar($otro['ana']->id, 'otra.persona@example.com', $tokenOtro)->assertOk();
    }

    public function test_the_limit_window_is_one_hour(): void
    {
        $extra = $this->profesionalesExtra(11);
        for ($i = 0; $i < 10; $i++) {
            $this->invitar($extra[$i]->id, "persona{$i}@example.com")->assertOk();
        }
        $this->invitar($extra[10]->id, 'persona10@example.com')->assertStatus(429);

        Carbon::setTestNow(now()->addMinutes(59));
        $this->invitar($extra[10]->id, 'persona10@example.com')->assertStatus(429);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->invitar($extra[10]->id, 'persona10@example.com')->assertOk();
        Carbon::setTestNow();
    }

    public function test_with_the_kill_switch_off_the_endpoint_answers_404_creates_nothing_and_sends_no_mail(): void
    {
        config(['portal_profesionales.habilitado' => false]);

        $this->invitar($this->negocio['ana']->id, 'ana.nueva@example.com')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/profesionales/{$this->negocio['ana']->id}/acceso", ['email' => 'ana.nueva@example.com'])
            ->assertNotFound();

        $this->assertSame(0, ProfesionalCuenta::count());
        $this->assertSame(0, ProfesionalVinculo::count());
        Mail::assertNothingSent();
    }

    public function test_turning_the_kill_switch_off_does_not_affect_the_rest_of_the_tenant_api(): void
    {
        config(['portal_profesionales.habilitado' => false]);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenTitular)->getJson('/api/profesionales')->assertOk();
    }

    // ── Concurrency backstops ───────────────────────────────────────────────

    public function test_losing_the_race_on_the_account_email_links_the_account_that_won(): void
    {
        $contrasena = Hash::make('la-que-puso-el-otro-invitador');
        // The competing request inserts the same email between our lookup and
        // our insert: the unique index rejects ours.
        $this->alPrimerSelectDe('profesional_cuentas', fn () => DB::table('profesional_cuentas')->insert([
            'email' => 'carrera@example.com',
            'password' => $contrasena,
            'activo' => true,
            'debe_cambiar_password' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->invitar($this->negocio['ana']->id, 'carrera@example.com')
            ->assertOk()
            ->assertExactJson(['message' => self::MENSAJE_OK]);

        $cuenta = ProfesionalCuenta::sole();
        $this->assertSame($contrasena, $cuenta->getRawOriginal('password'), 'The winner\'s password must not be replaced');
        $this->assertSame($cuenta->id, ProfesionalVinculo::sole()->cuenta_id);
    }

    public function test_losing_the_race_on_the_professional_link_answers_409_and_leaves_no_orphan_account(): void
    {
        $ganadora = $this->crearCuentaPortal('ganadora@example.com');
        $this->alPrimerSelectDe('profesional_vinculos', fn () => DB::table('profesional_vinculos')->insert([
            'cuenta_id' => $ganadora->id,
            'profesional_id' => $this->negocio['ana']->id,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->invitar($this->negocio['ana']->id, 'perdedora@example.com')
            ->assertStatus(409)
            ->assertJsonPath('code', 'PROFESIONAL_CON_ACCESO');

        $this->assertNull(ProfesionalCuenta::where('email', 'perdedora@example.com')->first(), 'The account created in the failed attempt must roll back');
        $this->assertSame($ganadora->id, ProfesionalVinculo::sole()->cuenta_id);
        Mail::assertNothingSent();
    }
}

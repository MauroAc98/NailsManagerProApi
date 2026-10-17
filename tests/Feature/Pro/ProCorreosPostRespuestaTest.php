<?php

namespace Tests\Feature\Pro;

use App\Mail\ProfesionalInvitacionMail;
use App\Mail\ProfesionalVinculadaMail;
use App\Models\Profesional;
use App\Models\ProfesionalCuenta;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * The invitation mails (new account, linked account) and the owner's password
 * reset mail go out AFTER the response. Otherwise the request that sends a mail
 * takes one SMTP round trip longer than the one that does not (an email that is
 * already linked, a reset that is a silent no-op), and that difference is a
 * measurable oracle on whether an email has an account.
 *
 * Every test here drives the kernel by hand: handle() produces the response,
 * the test looks at the world, and terminate() runs the after-response work
 * exactly once.
 */
class ProCorreosPostRespuestaTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    /** @var array{user: User, titular: Profesional, ana: Profesional, bea: Profesional} */
    private array $negocio;

    private string $tokenTitular;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->negocio = $this->crearNegocioPortal(['email' => 'titular@negocio.test', 'name' => 'Estudio Aurora']);
        $this->tokenTitular = $this->negocio['user']->createToken('test')->plainTextToken;
    }

    /** @return array{0: Request, 1: Response, 2: Kernel} */
    private function invitarSinTerminar(Profesional $profesional, string $email): array
    {
        return $this->manejarSinTerminar('POST', "/api/profesionales/{$profesional->id}/acceso", ['email' => $email], $this->tokenTitular);
    }

    /** @return array{0: Request, 1: Response, 2: Kernel} */
    private function resetearSinTerminar(Profesional $profesional): array
    {
        return $this->manejarSinTerminar('POST', "/api/profesionales/{$profesional->id}/acceso/reset", [], $this->tokenTitular);
    }

    private function cuentaPendiente(string $email = 'ana@x.com'): ProfesionalCuenta
    {
        $cuenta = $this->crearCuentaPortal($email, 'vieja-provisoria', ['debe_cambiar_password' => true]);
        $this->vincularPortal($cuenta, $this->negocio['ana'], true, null, true);

        return $cuenta;
    }

    // ── Invitation: new account ────────────────────────────────────────────

    public function test_the_invitation_mail_of_a_new_account_is_sent_only_after_the_response(): void
    {
        [$request, $respuesta, $kernel] = $this->invitarSinTerminar($this->negocio['ana'], 'ana.nueva@example.com');

        // Response ready: the invitation is committed but no mail has left yet.
        $this->assertSame(200, $respuesta->getStatusCode());
        Mail::assertNothingSent();
        $cuenta = ProfesionalCuenta::sole();
        $this->assertSame('ana.nueva@example.com', $cuenta->email);
        $this->assertSame(1, DB::table('profesional_vinculos')->count());

        $kernel->terminate($request, $respuesta);

        Mail::assertSentCount(1);
        $enviados = Mail::sent(ProfesionalInvitacionMail::class);
        $this->assertCount(1, $enviados);
        $correo = $enviados->first();
        $this->assertTrue($correo->hasTo('ana.nueva@example.com'));
        $this->assertTrue(
            Hash::check($correo->password, $cuenta->fresh()->getRawOriginal('password')),
            'The password mailed after the response must be the one stored'
        );
    }

    public function test_the_response_of_an_invitation_does_not_depend_on_the_mail(): void
    {
        $this->mailerGrabador(falla: true);
        Log::spy();

        [$request, $respuesta, $kernel] = $this->invitarSinTerminar($this->negocio['ana'], 'ana.nueva@example.com');
        $contenido = $respuesta->getContent();

        $this->assertSame(200, $respuesta->getStatusCode());
        // A failing SMTP server after the response must not blow up the terminate phase.
        $kernel->terminate($request, $respuesta);

        $this->assertSame(1, ProfesionalCuenta::count(), 'The account survives the mail failure');
        Log::shouldHaveReceived('error')->once();
        $this->assertStringNotContainsString('ana.nueva@example.com', $contenido);
    }

    public function test_a_failure_after_the_response_logs_neither_the_password_nor_the_address(): void
    {
        $registro = $this->mailerGrabador(falla: true);
        Log::spy();

        [$request, $respuesta, $kernel] = $this->invitarSinTerminar($this->negocio['ana'], 'ana.nueva@example.com');
        $kernel->terminate($request, $respuesta);

        $this->assertCount(1, $registro->mails);
        $password = $registro->mails[0]->password;
        $this->assertNotSame('', $password);
        foreach (['error', 'warning', 'info', 'debug', 'notice'] as $nivel) {
            Log::shouldNotHaveReceived($nivel, fn ($mensaje, $contexto = []) => str_contains(json_encode([$mensaje, $contexto]), $password)
                || str_contains(json_encode([$mensaje, $contexto]), 'ana.nueva@example.com'));
        }
    }

    public function test_the_invitation_mail_is_sent_outside_any_transaction(): void
    {
        $nivelBase = DB::transactionLevel();
        $registro = $this->mailerGrabador(falla: false);

        [$request, $respuesta, $kernel] = $this->invitarSinTerminar($this->negocio['ana'], 'ana.nueva@example.com');
        $this->assertCount(0, $registro->mails, 'Nothing is sent before the response');
        $kernel->terminate($request, $respuesta);

        $this->assertCount(1, $registro->mails);
        $this->assertSame([$nivelBase], $registro->niveles);
    }

    // ── Invitation: existing account ───────────────────────────────────────

    public function test_the_linked_mail_of_an_existing_account_is_sent_only_after_the_response(): void
    {
        $this->crearCuentaPortal('bea@example.com');

        [$request, $respuesta, $kernel] = $this->invitarSinTerminar($this->negocio['bea'], 'bea@example.com');

        $this->assertSame(200, $respuesta->getStatusCode());
        Mail::assertNothingSent();
        $this->assertSame(1, DB::table('profesional_vinculos')->count(), 'The link is already committed');

        $kernel->terminate($request, $respuesta);

        Mail::assertSentCount(1);
        $enviados = Mail::sent(ProfesionalVinculadaMail::class);
        $this->assertCount(1, $enviados);
        $this->assertTrue($enviados->first()->hasTo('bea@example.com'));
        Mail::assertNotSent(ProfesionalInvitacionMail::class);
    }

    public function test_a_new_and_an_existing_email_give_the_same_response_before_any_mail_leaves(): void
    {
        $this->crearCuentaPortal('bea@example.com');

        [, $nuevo] = $this->invitarSinTerminar($this->negocio['ana'], 'ana.nueva@example.com');
        [, $existente] = $this->invitarSinTerminar($this->negocio['bea'], 'bea@example.com');

        $this->assertSame($nuevo->getStatusCode(), $existente->getStatusCode());
        $this->assertSame($nuevo->getContent(), $existente->getContent());
        Mail::assertNothingSent();
    }

    // ── Invitation: paths that send nothing ────────────────────────────────

    public function test_re_inviting_the_same_email_sends_nothing_even_after_the_response(): void
    {
        $cuenta = $this->crearCuentaPortal('ana@x.com');
        $this->vincularPortal($cuenta, $this->negocio['ana']);

        [$request, $respuesta, $kernel] = $this->invitarSinTerminar($this->negocio['ana'], 'ana@x.com');
        $kernel->terminate($request, $respuesta);

        $this->assertSame(200, $respuesta->getStatusCode());
        Mail::assertNothingSent();
    }

    public function test_a_rejected_invitation_sends_nothing_even_after_the_response(): void
    {
        [$request, $respuesta, $kernel] = $this->invitarSinTerminar($this->negocio['titular'], 'ana.nueva@example.com');
        $kernel->terminate($request, $respuesta);

        $this->assertSame(422, $respuesta->getStatusCode());
        Mail::assertNothingSent();
        $this->assertSame(0, ProfesionalCuenta::count());
    }

    // ── Owner reset ────────────────────────────────────────────────────────

    public function test_the_reset_mail_is_sent_only_after_the_response_and_carries_the_stored_password(): void
    {
        $cuenta = $this->cuentaPendiente();
        $hashViejo = $cuenta->getRawOriginal('password');

        [$request, $respuesta, $kernel] = $this->resetearSinTerminar($this->negocio['ana']);

        $this->assertSame(200, $respuesta->getStatusCode());
        Mail::assertNothingSent();
        $cuenta->refresh();
        $this->assertNotSame($hashViejo, $cuenta->getRawOriginal('password'), 'The new password is already committed');

        $kernel->terminate($request, $respuesta);

        Mail::assertSentCount(1);
        $correo = Mail::sent(ProfesionalInvitacionMail::class)->sole();
        $this->assertTrue($correo->hasTo('ana@x.com'));
        $this->assertTrue(Hash::check($correo->password, $cuenta->fresh()->getRawOriginal('password')));
    }

    public function test_the_reset_mail_is_sent_outside_any_transaction(): void
    {
        $this->cuentaPendiente();
        $nivelBase = DB::transactionLevel();
        $registro = $this->mailerGrabador(falla: false);

        [$request, $respuesta, $kernel] = $this->resetearSinTerminar($this->negocio['ana']);
        $this->assertCount(0, $registro->mails);
        $kernel->terminate($request, $respuesta);

        $this->assertSame([$nivelBase], $registro->niveles);
    }

    public function test_a_reset_mail_failure_after_the_response_keeps_the_new_password_and_leaks_nothing(): void
    {
        $this->cuentaPendiente();
        $registro = $this->mailerGrabador(falla: true);
        Log::spy();

        [$request, $respuesta, $kernel] = $this->resetearSinTerminar($this->negocio['ana']);
        $kernel->terminate($request, $respuesta);

        $this->assertSame(200, $respuesta->getStatusCode());
        $password = $registro->mails[0]->password;
        $this->assertTrue(Hash::check($password, ProfesionalCuenta::sole()->getRawOriginal('password')));
        Log::shouldHaveReceived('error')->once();
        foreach (['error', 'warning', 'info', 'debug', 'notice'] as $nivel) {
            Log::shouldNotHaveReceived($nivel, fn ($mensaje, $contexto = []) => str_contains(json_encode([$mensaje, $contexto]), $password)
                || str_contains(json_encode([$mensaje, $contexto]), 'ana@x.com'));
        }
    }

    public function test_a_no_op_reset_sends_nothing_even_after_the_response_and_answers_like_a_real_one(): void
    {
        // A real reset, response only.
        $this->cuentaPendiente();
        [, $real] = $this->resetearSinTerminar($this->negocio['ana']);

        // An activated account: silent no-op.
        $activada = $this->crearCuentaPortal('bea@x.com', 'password-seguro', ['debe_cambiar_password' => false]);
        $this->vincularPortal($activada, $this->negocio['bea'], true, null, true);
        $hashActivada = $activada->getRawOriginal('password');
        Mail::fake();

        [$request, $noOp, $kernel] = $this->resetearSinTerminar($this->negocio['bea']);
        $kernel->terminate($request, $noOp);

        $this->assertSame($real->getStatusCode(), $noOp->getStatusCode());
        $this->assertSame($real->getContent(), $noOp->getContent());
        Mail::assertNothingSent();
        $this->assertSame($hashActivada, $activada->fresh()->getRawOriginal('password'));
    }

    public function test_the_titular_profile_reset_is_rejected_and_sends_nothing(): void
    {
        $this->vincularPortal($this->crearCuentaPortal('t@x.com'), $this->negocio['titular']);

        [$request, $respuesta, $kernel] = $this->resetearSinTerminar($this->negocio['titular']);
        $kernel->terminate($request, $respuesta);

        $this->assertSame(422, $respuesta->getStatusCode());
        Mail::assertNothingSent();
    }
}

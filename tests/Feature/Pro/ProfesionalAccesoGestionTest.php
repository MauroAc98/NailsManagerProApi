<?php

namespace Tests\Feature\Pro;

use App\Mail\ProfesionalInvitacionMail;
use App\Models\Profesional;
use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
use App\Models\User;
use App\Support\PasswordProvisoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * AM-04..AM-07 (owner side): the persona titular enables, disables, removes,
 * reads and (while the first login is still pending) resets the portal access
 * of HER professionals.
 *
 * Security-critical: a professional account can be SHARED with other negocios,
 * so every operation here may only ever touch THIS professional's link, never
 * the account (its `activo`, password, flag or tokens) and never another
 * negocio's link.
 */
class ProfesionalAccesoGestionTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private const EMAIL_TITULAR = 'titular@negocio.test';

    /** The fixed body of every 404 for a professional that is not hers (same as the invitation's). */
    private const NO_ENCONTRADA = [
        'message' => 'No encontramos a esa profesional.',
        'code' => 'PROFESIONAL_NO_ENCONTRADA',
    ];

    /** @var array{user: User, titular: Profesional, ana: Profesional, bea: Profesional} */
    private array $negocio;

    /** @var array{user: User, titular: Profesional, ana: Profesional, bea: Profesional} */
    private array $otroNegocio;

    private string $tokenTitular;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->negocio = $this->crearNegocioPortal(['email' => self::EMAIL_TITULAR, 'name' => 'Estudio Aurora']);
        $this->otroNegocio = $this->crearNegocioPortal(['name' => 'Estudio Otro']);
        $this->tokenTitular = $this->negocio['user']->createToken('test')->plainTextToken;
    }

    /** Owner-side call with the titular's tenant token (Sanctum caches the first user per app instance). */
    private function owner(string $metodo, string $ruta, array $cuerpo = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        // The mails leave after the response; drop the earlier requests' replayed tasks.
        $this->reiniciarTareasPostRespuesta();

        return $this->withToken($token ?? $this->tokenTitular)->json($metodo, $ruta, $cuerpo);
    }

    private function cambiarActivo(int|string $profesionalId, mixed $activo)
    {
        return $this->owner('PATCH', "/api/profesionales/{$profesionalId}/acceso", ['activo' => $activo]);
    }

    /** A professional-portal request on a link-scoped route; 200 while the link is usable. */
    private function comoProfesional(string $token, int $vinculoId)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->withHeader('X-Vinculo-Id', (string) $vinculoId)->getJson('/api/pro/servicios');
    }

    /**
     * An activated account linked to Ana (this negocio) and to Ana of the other
     * negocio, with one live full token.
     *
     * @return array{cuenta: ProfesionalCuenta, vinculo: ProfesionalVinculo, vinculoOtro: ProfesionalVinculo, token: string}
     */
    private function cuentaCompartida(string $email = 'ana@x.com'): array
    {
        $cuenta = $this->crearCuentaPortal($email);

        return [
            'cuenta' => $cuenta,
            'vinculo' => $this->vincularPortal($cuenta, $this->negocio['ana'], true, $this->negocio['user']),
            'vinculoOtro' => $this->vincularPortal($cuenta, $this->otroNegocio['ana'], true, $this->otroNegocio['user']),
            'token' => $this->tokenPara($cuenta),
        ];
    }

    /** @return array<string, mixed> the raw row, to compare before/after */
    private function fila(string $tabla, int $id): array
    {
        return (array) DB::table($tabla)->where('id', $id)->first();
    }

    // ── AM-04: enable / disable (6b.1) ──────────────────────────────────────

    public function test_disabling_cuts_that_link_on_the_next_request_and_keeps_the_token_and_the_other_negocio(): void
    {
        $c = $this->cuentaCompartida();
        $this->comoProfesional($c['token'], $c['vinculo']->id)->assertOk();

        $this->cambiarActivo($this->negocio['ana']->id, false)
            ->assertOk()
            ->assertExactJson(['message' => 'Acceso desactivado.', 'activo' => false]);

        $this->comoProfesional($c['token'], $c['vinculo']->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'VINCULO_NO_DISPONIBLE');
        // The same token keeps working against the other negocio.
        $this->comoProfesional($c['token'], $c['vinculoOtro']->id)->assertOk();
        $this->assertSame(1, $c['cuenta']->tokens()->count());
        $this->assertFalse($c['vinculo']->fresh()->activo);
    }

    public function test_re_enabling_works_again_with_the_same_token(): void
    {
        $c = $this->cuentaCompartida();
        $this->cambiarActivo($this->negocio['ana']->id, false)->assertOk();
        $this->comoProfesional($c['token'], $c['vinculo']->id)->assertForbidden();

        $this->cambiarActivo($this->negocio['ana']->id, true)
            ->assertOk()
            ->assertExactJson(['message' => 'Acceso activado.', 'activo' => true]);

        $this->comoProfesional($c['token'], $c['vinculo']->id)->assertOk();
        $this->assertTrue($c['vinculo']->fresh()->activo);
    }

    public function test_toggling_never_touches_the_account_or_the_other_negocios_link(): void
    {
        $c = $this->cuentaCompartida();
        $cuentaAntes = $this->fila('profesional_cuentas', $c['cuenta']->id);
        $otroAntes = $this->fila('profesional_vinculos', $c['vinculoOtro']->id);

        $this->cambiarActivo($this->negocio['ana']->id, false)->assertOk();
        $this->cambiarActivo($this->negocio['ana']->id, true)->assertOk();
        $this->cambiarActivo($this->negocio['ana']->id, false)->assertOk();

        $this->assertSame($cuentaAntes, $this->fila('profesional_cuentas', $c['cuenta']->id));
        $this->assertSame($otroAntes, $this->fila('profesional_vinculos', $c['vinculoOtro']->id));
    }

    public function test_disabling_never_changes_the_active_flag_of_an_account_that_is_inactive(): void
    {
        $cuenta = $this->crearCuentaPortal('ana@x.com', 'password-seguro', ['activo' => false]);
        $this->vincularPortal($cuenta, $this->negocio['ana']);

        $this->cambiarActivo($this->negocio['ana']->id, true)->assertOk();
        $this->cambiarActivo($this->negocio['ana']->id, false)->assertOk();

        $this->assertFalse($cuenta->fresh()->activo);
    }

    public function test_the_toggle_is_idempotent(): void
    {
        $this->vincularPortal($this->crearCuentaPortal(), $this->negocio['ana']);

        $this->cambiarActivo($this->negocio['ana']->id, false)->assertOk();
        $this->cambiarActivo($this->negocio['ana']->id, false)->assertOk()->assertJsonPath('activo', false);
        $this->cambiarActivo($this->negocio['ana']->id, true)->assertOk();
        $this->cambiarActivo($this->negocio['ana']->id, true)->assertOk()->assertJsonPath('activo', true);
    }

    public function test_only_the_link_of_that_professional_is_toggled(): void
    {
        $cuenta = $this->crearCuentaPortal();
        $vAna = $this->vincularPortal($cuenta, $this->negocio['ana']);
        $vBea = $this->vincularPortal($cuenta, $this->negocio['bea']);

        $this->cambiarActivo($this->negocio['ana']->id, false)->assertOk();

        $this->assertFalse($vAna->fresh()->activo);
        $this->assertTrue($vBea->fresh()->activo);
    }

    public function test_a_foreign_professional_is_a_404_with_the_fixed_body_and_her_link_is_untouched(): void
    {
        $cuenta = $this->crearCuentaPortal();
        $vinculo = $this->vincularPortal($cuenta, $this->otroNegocio['ana']);
        $antes = $this->fila('profesional_vinculos', $vinculo->id);

        $ajena = $this->cambiarActivo($this->otroNegocio['ana']->id, false);
        $inexistente = $this->cambiarActivo(999999, false);
        $malformado = $this->cambiarActivo('abc', false);

        foreach ([$ajena, $inexistente, $malformado] as $respuesta) {
            $respuesta->assertNotFound()->assertExactJson(self::NO_ENCONTRADA);
        }
        $this->assertSame($ajena->getContent(), $inexistente->getContent());
        $this->assertSame($ajena->getContent(), $malformado->getContent());
        $this->assertSame($antes, $this->fila('profesional_vinculos', $vinculo->id));
    }

    public function test_a_professional_without_access_is_a_404_with_its_own_code(): void
    {
        $this->cambiarActivo($this->negocio['ana']->id, false)
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Esa profesional no tiene acceso al portal.',
                'code' => 'PROFESIONAL_SIN_ACCESO',
            ]);
        $this->assertSame(0, ProfesionalVinculo::count());
    }

    public function test_activo_is_required_and_must_be_a_boolean_and_nothing_changes_otherwise(): void
    {
        $vinculo = $this->vincularPortal($this->crearCuentaPortal(), $this->negocio['ana']);

        $this->owner('PATCH', "/api/profesionales/{$this->negocio['ana']->id}/acceso", [])
            ->assertStatus(422)->assertJsonValidationErrors('activo');
        $this->cambiarActivo($this->negocio['ana']->id, 'quizas')->assertStatus(422)->assertJsonValidationErrors('activo');
        $this->cambiarActivo($this->negocio['ana']->id, ['x'])->assertStatus(422)->assertJsonValidationErrors('activo');

        $this->assertTrue($vinculo->fresh()->activo);
    }

    public function test_the_titular_profile_cannot_be_enabled_but_can_still_be_disabled(): void
    {
        // Cannot happen through the API (the invitation rejects it): forced in the DB.
        $vinculo = $this->vincularPortal($this->crearCuentaPortal(), $this->negocio['titular'], false);

        $this->cambiarActivo($this->negocio['titular']->id, true)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PERFIL_DE_LA_PERSONA_TITULAR');
        $this->assertFalse($vinculo->fresh()->activo);

        $vinculo->update(['activo' => true]);
        $this->cambiarActivo($this->negocio['titular']->id, false)->assertOk();
        $this->assertFalse($vinculo->fresh()->activo);
    }

    // ── AM-06: remove (6b.2) ────────────────────────────────────────────────

    private function quitar(int|string $profesionalId)
    {
        return $this->owner('DELETE', "/api/profesionales/{$profesionalId}/acceso");
    }

    public function test_removing_deletes_only_that_link_and_keeps_the_account_the_tokens_and_the_other_negocio(): void
    {
        $c = $this->cuentaCompartida();
        $cuentaAntes = $this->fila('profesional_cuentas', $c['cuenta']->id);
        $otroAntes = $this->fila('profesional_vinculos', $c['vinculoOtro']->id);

        $this->quitar($this->negocio['ana']->id)
            ->assertOk()
            ->assertExactJson(['message' => 'Acceso quitado.']);

        $this->assertNull(ProfesionalVinculo::find($c['vinculo']->id));
        $this->assertSame($cuentaAntes, $this->fila('profesional_cuentas', $c['cuenta']->id));
        $this->assertSame($otroAntes, $this->fila('profesional_vinculos', $c['vinculoOtro']->id));
        $this->assertSame(1, $c['cuenta']->tokens()->count());
        // The removed link is gone for the professional; the other negocio still works.
        $this->comoProfesional($c['token'], $c['vinculo']->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'VINCULO_NO_DISPONIBLE');
        $this->comoProfesional($c['token'], $c['vinculoOtro']->id)->assertOk();
    }

    public function test_removing_one_professional_leaves_the_links_of_her_other_professionals_alone(): void
    {
        $cuenta = $this->crearCuentaPortal();
        $this->vincularPortal($cuenta, $this->negocio['ana']);
        $vBea = $this->vincularPortal($cuenta, $this->negocio['bea']);

        $this->quitar($this->negocio['ana']->id)->assertOk();

        $this->assertSame([$vBea->id], ProfesionalVinculo::pluck('id')->all());
    }

    public function test_removing_the_last_link_keeps_the_account_but_login_gives_the_generic_401(): void
    {
        $cuenta = $this->crearCuentaPortal('ana@x.com', 'password-seguro');
        $this->vincularPortal($cuenta, $this->negocio['ana']);
        $this->postJson('/api/pro/login', ['email' => 'ana@x.com', 'password' => 'password-seguro'])->assertOk();

        $this->quitar($this->negocio['ana']->id)->assertOk();

        $this->assertNotNull(ProfesionalCuenta::find($cuenta->id));
        $this->postJson('/api/pro/login', ['email' => 'ana@x.com', 'password' => 'password-seguro'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Las credenciales son incorrectas.']);
    }

    public function test_after_removing_the_same_professional_can_be_invited_again_without_touching_the_account(): void
    {
        $c = $this->cuentaCompartida();
        $cuentaAntes = $this->fila('profesional_cuentas', $c['cuenta']->id);
        $this->quitar($this->negocio['ana']->id)->assertOk();

        $this->owner('POST', "/api/profesionales/{$this->negocio['ana']->id}/acceso", ['email' => 'ana@x.com'])->assertOk();

        $nuevo = ProfesionalVinculo::where('profesional_id', $this->negocio['ana']->id)->sole();
        $this->assertSame($c['cuenta']->id, $nuevo->cuenta_id);
        $this->assertSame($cuentaAntes, $this->fila('profesional_cuentas', $c['cuenta']->id));
        // The token the account already had works on the new link at once.
        $this->comoProfesional($c['token'], $nuevo->id)->assertOk();
        // And a different email can now be invited too.
        $this->quitar($this->negocio['ana']->id)->assertOk();
        $this->owner('POST', "/api/profesionales/{$this->negocio['ana']->id}/acceso", ['email' => 'otra@x.com'])->assertOk();
        $this->assertSame(2, ProfesionalCuenta::count());
    }

    public function test_removing_a_foreign_professional_is_a_404_with_the_fixed_body_and_her_link_survives(): void
    {
        $vinculo = $this->vincularPortal($this->crearCuentaPortal(), $this->otroNegocio['ana']);
        $antes = $this->fila('profesional_vinculos', $vinculo->id);

        $ajena = $this->quitar($this->otroNegocio['ana']->id);
        $inexistente = $this->quitar(999999);
        $malformado = $this->quitar('abc');

        foreach ([$ajena, $inexistente, $malformado] as $respuesta) {
            $respuesta->assertNotFound()->assertExactJson(self::NO_ENCONTRADA);
        }
        $this->assertSame($antes, $this->fila('profesional_vinculos', $vinculo->id));
    }

    public function test_removing_twice_or_without_access_is_a_404_with_its_own_code(): void
    {
        $this->vincularPortal($this->crearCuentaPortal(), $this->negocio['ana']);
        $this->quitar($this->negocio['ana']->id)->assertOk();

        $this->quitar($this->negocio['ana']->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'PROFESIONAL_SIN_ACCESO');
        $this->quitar($this->negocio['bea']->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'PROFESIONAL_SIN_ACCESO');
    }

    // ── AM-07: access status (6b.3) ─────────────────────────────────────────

    private function estado(int|string $profesionalId)
    {
        return $this->owner('GET', "/api/profesionales/{$profesionalId}/acceso");
    }

    public function test_status_of_a_pending_account_has_exactly_the_four_keys(): void
    {
        $cuenta = $this->crearCuentaPortal('ana@x.com', 'provisoria-1', ['debe_cambiar_password' => true]);
        $this->vincularPortal($cuenta, $this->negocio['ana'], true, null, true);

        $this->estado($this->negocio['ana']->id)
            ->assertOk()
            ->assertExactJson([
                'tiene_acceso' => true,
                'email' => 'ana@x.com',
                'activo' => true,
                'pendiente_de_activacion' => true,
            ]);
    }

    public function test_status_of_an_activated_account_and_of_a_disabled_link(): void
    {
        $this->vincularPortal($this->crearCuentaPortal('ana@x.com'), $this->negocio['ana'], true, null, true);
        $this->vincularPortal($this->crearCuentaPortal('bea@x.com'), $this->negocio['bea'], false, null, true);

        $this->estado($this->negocio['ana']->id)->assertOk()->assertExactJson([
            'tiene_acceso' => true,
            'email' => 'ana@x.com',
            'activo' => true,
            'pendiente_de_activacion' => false,
        ]);
        $this->estado($this->negocio['bea']->id)->assertOk()->assertExactJson([
            'tiene_acceso' => true,
            'email' => 'bea@x.com',
            'activo' => false,
            'pendiente_de_activacion' => false,
        ]);
    }

    public function test_status_without_access_says_so_with_the_same_keys(): void
    {
        $this->estado($this->negocio['ana']->id)
            ->assertOk()
            ->assertExactJson([
                'tiene_acceso' => false,
                'email' => null,
                'activo' => null,
                'pendiente_de_activacion' => null,
            ]);
    }

    public function test_status_reports_this_link_only_and_never_account_level_or_cross_negocio_data(): void
    {
        $cuenta = $this->crearCuentaPortal('ana@x.com');
        $cuenta->forceFill([
            'ultimo_login_at' => '2026-10-15 09:41:17',
            'ultimo_login_ip' => '203.0.113.77',
            'activo' => false,
        ])->save();
        $this->vincularPortal($cuenta, $this->negocio['ana'], true, null, true);
        $this->vincularPortal($cuenta, $this->otroNegocio['ana'], false);
        $token = $this->tokenPara($cuenta);

        $respuesta = $this->estado($this->negocio['ana']->id)->assertOk();

        // `activo` is the link's (true), not the account's (false) nor the other negocio's link (false).
        $respuesta->assertExactJson([
            'tiene_acceso' => true,
            'email' => 'ana@x.com',
            'activo' => true,
            'pendiente_de_activacion' => false,
        ]);
        $cuerpo = $respuesta->getContent();
        foreach (['203.0.113.77', '2026-10-15', 'Estudio Otro', 'ultimo_login', 'password', $token, (string) $cuenta->password] as $canario) {
            $this->assertStringNotContainsString($canario, $cuerpo);
        }
    }

    public function test_status_of_a_foreign_nonexistent_or_malformed_professional_is_the_fixed_404(): void
    {
        $this->vincularPortal($this->crearCuentaPortal(), $this->otroNegocio['ana']);

        $ajena = $this->estado($this->otroNegocio['ana']->id);
        $inexistente = $this->estado(999999);
        $malformado = $this->estado('abc');

        foreach ([$ajena, $inexistente, $malformado] as $respuesta) {
            $respuesta->assertNotFound()->assertExactJson(self::NO_ENCONTRADA);
        }
    }

    public function test_the_list_has_one_entry_per_professional_of_the_titular_and_none_of_other_negocios(): void
    {
        $this->vincularPortal($this->crearCuentaPortal('ana@x.com', 'provisoria-1', ['debe_cambiar_password' => true]), $this->negocio['ana'], true, null, true);
        $this->vincularPortal($this->crearCuentaPortal('bea@x.com'), $this->negocio['bea'], false, null, true);
        $this->vincularPortal($this->crearCuentaPortal('otra@x.com'), $this->otroNegocio['ana']);

        $respuesta = $this->owner('GET', '/api/profesionales/accesos')->assertOk();

        $respuesta->assertExactJson(['data' => [
            ['profesional_id' => $this->negocio['titular']->id, 'tiene_acceso' => false, 'email' => null, 'activo' => null, 'pendiente_de_activacion' => null],
            ['profesional_id' => $this->negocio['ana']->id, 'tiene_acceso' => true, 'email' => 'ana@x.com', 'activo' => true, 'pendiente_de_activacion' => true],
            ['profesional_id' => $this->negocio['bea']->id, 'tiene_acceso' => true, 'email' => 'bea@x.com', 'activo' => false, 'pendiente_de_activacion' => false],
        ]]);
        $this->assertStringNotContainsString('otra@x.com', $respuesta->getContent());
    }

    public function test_the_list_costs_the_same_number_of_queries_for_two_or_ten_professionals(): void
    {
        $contar = function (): int {
            $this->app['auth']->forgetGuards();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->withToken($this->tokenTitular)->getJson('/api/profesionales/accesos')->assertOk();
            $consultas = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $consultas;
        };
        // One linked professional first: with no links at all the accounts query is skipped.
        $this->vincularPortal($this->crearCuentaPortal('primera@x.com'), $this->negocio['ana']);
        $contar(); // warm up
        $pocas = $contar();

        for ($i = 0; $i < 8; $i++) {
            $profesional = $this->crearProfesionalPortal($this->negocio['user'], "Extra {$i}");
            $this->vincularPortal($this->crearCuentaPortal("extra{$i}@x.com"), $profesional);
        }

        $this->assertSame($pocas, $contar());
    }

    public function test_the_list_route_does_not_swallow_or_break_the_existing_professionals_index(): void
    {
        $this->owner('GET', '/api/profesionales')->assertOk();
        $this->owner('GET', '/api/profesionales/accesos')->assertOk()->assertJsonStructure(['data']);
    }

    // ── AM-05: reset only an account this invitation created, still pending and not shared (6b.4) ──

    private const MENSAJE_RESET = 'Listo. La persona va a recibir un email con una contraseña provisoria nueva.';

    private function resetear(int|string $profesionalId)
    {
        return $this->owner('POST', "/api/profesionales/{$profesionalId}/acceso/reset");
    }

    /** A linked account that never logged in: the provisional password is still the one in the DB. */
    private function cuentaPendiente(string $email = 'ana@x.com', string $password = 'vieja-provisoria', ?Profesional $profesional = null): ProfesionalCuenta
    {
        $cuenta = $this->crearCuentaPortal($email, $password, ['debe_cambiar_password' => true]);
        $this->vincularPortal($cuenta, $profesional ?? $this->negocio['ana'], true, null, true);

        return $cuenta;
    }

    /** The provisional password carried by the one invitation mail sent so far. */
    private function passwordEnviada(): string
    {
        $enviados = Mail::sent(ProfesionalInvitacionMail::class);
        $this->assertCount(1, $enviados);

        return $enviados->first()->password;
    }

    private function loginPro(string $email, string $password)
    {
        $this->app['auth']->forgetGuards();
        // A reset before this login left its mail task behind: do not replay it here.
        $this->reiniciarTareasPostRespuesta();

        return $this->postJson('/api/pro/login', ['email' => $email, 'password' => $password]);
    }

    public function test_resetting_a_pending_account_sets_a_new_provisional_password_that_only_works_through_the_restricted_flow(): void
    {
        $cuenta = $this->cuentaPendiente();
        $hashViejo = $cuenta->getRawOriginal('password');
        $this->loginPro('ana@x.com', 'vieja-provisoria')->assertOk()->assertJsonPath('debe_cambiar_password', true);
        $this->assertSame(1, $cuenta->tokens()->count());

        $respuesta = $this->resetear($this->negocio['ana']->id)
            ->assertOk()
            ->assertExactJson(['message' => self::MENSAJE_RESET]);

        $nueva = $this->passwordEnviada();
        $this->assertMatchesRegularExpression('/\A['.preg_quote(PasswordProvisoria::ALFABETO, '/').']{'.PasswordProvisoria::LONGITUD.'}\z/', $nueva);
        $this->assertStringNotContainsString($nueva, $respuesta->getContent());
        Mail::assertSent(ProfesionalInvitacionMail::class, fn ($m) => $m->hasTo('ana@x.com') && $m->email === 'ana@x.com');
        $cuenta->refresh();
        $this->assertNotSame($hashViejo, $cuenta->getRawOriginal('password'));
        $this->assertTrue($cuenta->debe_cambiar_password);
        $this->assertTrue($cuenta->activo);
        // The token of the old provisional password is dead.
        $this->assertSame(0, $cuenta->tokens()->count());
        // Old password fails, new one opens the forced-change flow only.
        $this->loginPro('ana@x.com', 'vieja-provisoria')->assertUnauthorized();
        $this->loginPro('ana@x.com', $nueva)
            ->assertOk()
            ->assertJsonPath('debe_cambiar_password', true);
        $this->assertSame(['pro:cambiar-password'], $cuenta->tokens()->sole()->abilities);
    }

    public function test_a_second_reset_supersedes_the_first_password(): void
    {
        $this->cuentaPendiente();
        $this->resetear($this->negocio['ana']->id)->assertOk();
        $primera = Mail::sent(ProfesionalInvitacionMail::class)->first()->password;

        $this->resetear($this->negocio['ana']->id)->assertOk();

        $enviados = Mail::sent(ProfesionalInvitacionMail::class);
        $this->assertCount(2, $enviados);
        $segunda = $enviados->last()->password;
        $this->assertNotSame($primera, $segunda);
        $this->loginPro('ana@x.com', $primera)->assertUnauthorized();
        $this->loginPro('ana@x.com', $segunda)->assertOk();
    }

    public function test_resetting_an_activated_account_created_here_is_a_silent_no_op_with_the_success_response(): void
    {
        $cuenta = $this->crearCuentaPortal('ana@x.com');
        $vinculo = $this->vincularPortal($cuenta, $this->negocio['ana'], true, null, true);
        $token = $this->tokenPara($cuenta);
        $antes = $this->fila('profesional_cuentas', $cuenta->id);

        $this->resetear($this->negocio['ana']->id)
            ->assertOk()
            ->assertExactJson(['message' => self::MENSAJE_RESET]);

        $this->assertSame($antes, $this->fila('profesional_cuentas', $cuenta->id));
        $this->assertSame(1, $cuenta->tokens()->count());
        $this->comoProfesional($token, $vinculo->id)->assertOk();
        $this->loginPro('ana@x.com', 'password-seguro')->assertOk()->assertJsonPath('debe_cambiar_password', false);
        Mail::assertNothingSent();
    }

    public function test_resetting_never_changes_the_active_flag_of_the_account_or_the_link(): void
    {
        $cuenta = $this->crearCuentaPortal('ana@x.com', 'vieja-provisoria', ['debe_cambiar_password' => true, 'activo' => false]);
        $vinculo = $this->vincularPortal($cuenta, $this->negocio['ana'], false, null, true);

        $this->resetear($this->negocio['ana']->id)->assertOk();

        $this->assertFalse($cuenta->fresh()->activo);
        $this->assertFalse($vinculo->fresh()->activo);
    }

    public function test_a_foreign_nonexistent_or_malformed_professional_is_the_fixed_404_and_nothing_is_touched(): void
    {
        $cuenta = $this->cuentaPendiente('otra@x.com', 'provisoria-ajena', $this->otroNegocio['ana']);
        $antes = $this->fila('profesional_cuentas', $cuenta->id);

        $ajena = $this->resetear($this->otroNegocio['ana']->id);
        $inexistente = $this->resetear(999999);
        $malformado = $this->resetear('abc');

        foreach ([$ajena, $inexistente, $malformado] as $respuesta) {
            $respuesta->assertNotFound()->assertExactJson(self::NO_ENCONTRADA);
        }
        $this->assertSame($antes, $this->fila('profesional_cuentas', $cuenta->id));
        Mail::assertNothingSent();
    }

    public function test_resetting_a_professional_without_access_is_a_404_with_its_own_code_and_sends_nothing(): void
    {
        $this->resetear($this->negocio['ana']->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'PROFESIONAL_SIN_ACCESO');

        $this->assertSame(0, ProfesionalCuenta::count());
        Mail::assertNothingSent();
    }

    public function test_the_titular_profile_is_never_reset(): void
    {
        $cuenta = $this->crearCuentaPortal('titular@x.com', 'vieja-provisoria', ['debe_cambiar_password' => true]);
        $this->vincularPortal($cuenta, $this->negocio['titular'], true, null, true);
        $antes = $this->fila('profesional_cuentas', $cuenta->id);

        $this->resetear($this->negocio['titular']->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PERFIL_DE_LA_PERSONA_TITULAR');

        $this->assertSame($antes, $this->fila('profesional_cuentas', $cuenta->id));
        Mail::assertNothingSent();
    }

    public function test_every_reset_path_pays_the_same_hashing_cost(): void
    {
        $extra = fn (string $nombre) => $this->crearProfesionalPortal($this->negocio['user'], $nombre);
        // Acts: created here, pending.
        $this->cuentaPendiente('pendiente@x.com', 'vieja-provisoria', $this->negocio['ana']);
        // No-ops: created here but activated; pending but pre-existing; created here, pending, but shared.
        $this->vincularPortal($this->crearCuentaPortal('activa@x.com'), $this->negocio['bea'], true, null, true);
        $this->vincularPortal($this->crearCuentaPortal('previa@x.com', 'provisoria-2', ['debe_cambiar_password' => true]), $extra('Previa'));
        $compartida = $this->cuentaPendiente('compartida@x.com', 'provisoria-3', $extra('Compartida'));
        $this->vincularPortal($compartida, $this->otroNegocio['ana']);

        $contador = $this->contarHashes();
        $profesionales = Profesional::where('user_id', $this->negocio['user']->id)->where('id', '>', $this->negocio['titular']->id)->orderBy('id')->get();
        $this->assertCount(4, $profesionales);
        foreach ($profesionales as $profesional) {
            $contador->llamadas = 0;
            $this->resetear($profesional->id)->assertOk();
            $this->assertSame(1, $contador->llamadas, "hashing cost differs for {$profesional->nombre}");
        }
    }

    public function test_the_mail_is_sent_after_the_transaction_has_committed(): void
    {
        $this->cuentaPendiente();
        $nivelBase = DB::transactionLevel();
        $registro = $this->mailerGrabador(falla: false);

        $this->resetear($this->negocio['ana']->id)->assertOk();

        $this->assertCount(1, $registro->mails);
        $this->assertSame([$nivelBase], $registro->niveles, 'Mail::send must run outside the reset transaction');
    }

    public function test_a_mail_failure_keeps_the_new_password_and_logs_neither_the_password_nor_the_email(): void
    {
        $this->cuentaPendiente();
        $registro = $this->mailerGrabador(falla: true);
        Log::spy();

        $respuesta = $this->resetear($this->negocio['ana']->id)
            ->assertOk()
            ->assertExactJson(['message' => self::MENSAJE_RESET]);

        $password = $registro->mails[0]->password;
        $this->assertStringNotContainsString($password, $respuesta->getContent());
        // The reset happened: the password that could not be mailed is the live one (the titular can reset again).
        $this->loginPro('ana@x.com', $password)->assertOk();
        Log::shouldHaveReceived('error')->once();
        foreach (['error', 'warning', 'info', 'debug'] as $nivel) {
            Log::shouldNotHaveReceived($nivel, fn ($mensaje, $contexto = []) => str_contains(
                json_encode([$mensaje, $contexto]),
                $password
            ) || str_contains(json_encode([$mensaje, $contexto]), 'ana@x.com'));
        }
    }

    public function test_the_provisional_password_is_never_logged_on_success(): void
    {
        $this->cuentaPendiente();
        Log::spy();

        $this->resetear($this->negocio['ana']->id)->assertOk();

        $password = $this->passwordEnviada();
        foreach (['error', 'warning', 'info', 'debug', 'notice'] as $nivel) {
            Log::shouldNotHaveReceived($nivel, fn ($mensaje, $contexto = []) => str_contains(json_encode([$mensaje, $contexto]), $password));
        }
    }

    public function test_resets_share_the_hourly_mail_limit_with_invitations(): void
    {
        $this->cuentaPendiente();
        for ($i = 0; $i < 10; $i++) {
            $this->resetear($this->negocio['ana']->id)->assertOk();
        }

        $limitada = $this->resetear($this->negocio['ana']->id);
        $invitacion = $this->owner('POST', "/api/profesionales/{$this->negocio['bea']->id}/acceso", ['email' => 'bea@x.com']);

        $limitada->assertStatus(429)->assertJsonPath('code', 'INVITACIONES_LIMITE');
        $invitacion->assertStatus(429);
        Mail::assertSentCount(10);
    }

    // ── No account-existence oracle: the titular only ever sees or acts on accounts HER invitation created ──

    /**
     * Five professionals whose access looks the same to the titular but whose
     * accounts are not. The first account was created by THIS invitation and is
     * still pending; the other four already existed in different states
     * (activated, activated and shared, pending left behind by another negocio,
     * pending and shared).
     *
     * @return array<string, array{Profesional, string}> label => [professional, account email]
     */
    private function escenarios(): array
    {
        $extra = fn (string $nombre) => $this->crearProfesionalPortal($this->negocio['user'], $nombre);
        $extraOtro = fn (string $nombre) => $this->crearProfesionalPortal($this->otroNegocio['user'], $nombre);
        $tokenOtra = $this->otroNegocio['user']->createToken('otra')->plainTextToken;
        $invitar = fn (Profesional $p, string $email, ?string $token = null) => $this->owner('POST', "/api/profesionales/{$p->id}/acceso", ['email' => $email], $token)->assertOk();
        $escenarios = [];

        $invitar($this->negocio['ana'], 'nueva@x.com');
        $escenarios['creada aqui, pendiente'] = [$this->negocio['ana'], 'nueva@x.com'];

        $this->vincularPortal($this->crearCuentaPortal('activada@x.com'), $this->negocio['bea']);
        $escenarios['previa, activada'] = [$this->negocio['bea'], 'activada@x.com'];

        $compartida = $this->crearCuentaPortal('compartida@x.com');
        $tres = $extra('Tres');
        $this->vincularPortal($compartida, $tres);
        $this->vincularPortal($compartida, $this->otroNegocio['bea']);
        $escenarios['previa, activada y compartida'] = [$tres, 'compartida@x.com'];

        // Another negocio created the account and later removed its link: it is still pending, but not ours.
        $invitar($this->otroNegocio['ana'], 'huerfana@x.com', $tokenOtra);
        $this->owner('DELETE', "/api/profesionales/{$this->otroNegocio['ana']->id}/acceso", [], $tokenOtra)->assertOk();
        $cuatro = $extra('Cuatro');
        $invitar($cuatro, 'huerfana@x.com');
        $escenarios['previa, pendiente'] = [$cuatro, 'huerfana@x.com'];

        // Created (and owned) by another negocio, pending, then linked here as well.
        $invitar($extraOtro('Otra Extra'), 'pendiente@x.com', $tokenOtra);
        $cinco = $extra('Cinco');
        $invitar($cinco, 'pendiente@x.com');
        $escenarios['previa, pendiente y compartida'] = [$cinco, 'pendiente@x.com'];

        Mail::fake(); // forget the mails sent while building the scenarios

        return $escenarios;
    }

    /** Everything a titular can observe of a response, with the (known) email masked and volatile headers dropped. */
    private function huella($respuesta, string $email): array
    {
        $cabeceras = $respuesta->headers->all();
        unset($cabeceras['date'], $cabeceras['x-ratelimit-remaining'], $cabeceras['retry-after']);

        return [
            'status' => $respuesta->getStatusCode(),
            'cuerpo' => str_replace($email, '<email>', $respuesta->getContent()),
            'cabeceras' => $cabeceras,
        ];
    }

    public function test_status_is_identical_for_an_account_created_here_and_for_every_account_that_already_existed(): void
    {
        $huellas = [];
        foreach ($this->escenarios() as $nombre => [$profesional, $email]) {
            $huellas[$nombre] = $this->huella($this->estado($profesional->id)->assertOk(), $email);
        }

        $this->assertCount(5, $huellas);
        $esperada = reset($huellas);
        foreach ($huellas as $nombre => $huella) {
            $this->assertSame($esperada, $huella, "status differs for: {$nombre}");
        }
        $this->assertSame(
            ['tiene_acceso' => true, 'email' => '<email>', 'activo' => true, 'pendiente_de_activacion' => true],
            json_decode($esperada['cuerpo'], true),
        );
    }

    public function test_the_list_is_identical_for_an_account_created_here_and_for_every_account_that_already_existed(): void
    {
        $escenarios = $this->escenarios();

        $entradas = collect($this->owner('GET', '/api/profesionales/accesos')->assertOk()->json('data'))->keyBy('profesional_id');

        $this->assertCount(5, $escenarios);
        foreach ($escenarios as $nombre => [$profesional, $email]) {
            $entrada = $entradas[$profesional->id];
            $this->assertSame($email, $entrada['email']);
            unset($entrada['profesional_id'], $entrada['email']);
            $this->assertSame(['tiene_acceso' => true, 'activo' => true, 'pendiente_de_activacion' => true], $entrada, "list differs for: {$nombre}");
        }
    }

    public function test_pending_is_false_only_for_an_account_created_here_that_already_logged_in(): void
    {
        $this->owner('POST', "/api/profesionales/{$this->negocio['ana']->id}/acceso", ['email' => 'nueva@x.com'])->assertOk();
        $this->estado($this->negocio['ana']->id)->assertJsonPath('pendiente_de_activacion', true);

        ProfesionalCuenta::where('email', 'nueva@x.com')->sole()->forceFill(['debe_cambiar_password' => false])->save();

        $this->estado($this->negocio['ana']->id)->assertJsonPath('pendiente_de_activacion', false);
        $lista = collect($this->owner('GET', '/api/profesionales/accesos')->json('data'))->firstWhere('profesional_id', $this->negocio['ana']->id);
        $this->assertFalse($lista['pendiente_de_activacion']);
    }

    public function test_reset_answers_the_same_for_an_account_created_here_and_for_every_account_that_already_existed_but_only_acts_on_the_first(): void
    {
        $escenarios = $this->escenarios();
        $instantanea = function (string $email): array {
            $cuenta = ProfesionalCuenta::where('email', $email)->sole();

            return [
                'cuenta' => $this->fila('profesional_cuentas', $cuenta->id),
                'vinculos' => DB::table('profesional_vinculos')->where('cuenta_id', $cuenta->id)->orderBy('id')->get()->map(fn ($f) => (array) $f)->all(),
                'tokens' => $cuenta->tokens()->count(),
            ];
        };
        $antes = [];
        foreach ($escenarios as $nombre => [$profesional, $email]) {
            ProfesionalCuenta::where('email', $email)->sole()->createToken('pro-portal', ['pro:cambiar-password']);
            $antes[$nombre] = $instantanea($email);
            $this->assertSame(1, $antes[$nombre]['tokens']);
        }

        $huellas = [];
        foreach ($escenarios as $nombre => [$profesional, $email]) {
            $huellas[$nombre] = $this->huella($this->resetear($profesional->id)->assertOk(), $email);
        }

        $this->assertCount(5, $huellas);
        $esperada = reset($huellas);
        foreach ($huellas as $nombre => $huella) {
            $this->assertSame($esperada, $huella, "reset response differs for: {$nombre}");
        }
        $this->assertSame(['message' => self::MENSAJE_RESET], json_decode($esperada['cuerpo'], true));

        foreach ($escenarios as $nombre => [$profesional, $email]) {
            $despues = $instantanea($email);
            if ($nombre === 'creada aqui, pendiente') {
                $this->assertNotSame($antes[$nombre]['cuenta']['password'], $despues['cuenta']['password']);
                $this->assertSame(0, $despues['tokens']);
                $this->assertSame($antes[$nombre]['vinculos'], $despues['vinculos']);

                continue;
            }
            $this->assertSame($antes[$nombre], $despues, "reset must not touch: {$nombre}");
        }
        Mail::assertSentCount(1);
        Mail::assertSent(ProfesionalInvitacionMail::class, fn ($m) => $m->hasTo('nueva@x.com'));
    }

    public function test_a_pending_account_shared_by_two_negocios_cannot_be_reset_by_either_titular(): void
    {
        $tokenOtra = $this->otroNegocio['user']->createToken('otra')->plainTextToken;
        // The other negocio creates it (so its link is the owned one); this negocio links it afterwards.
        $this->owner('POST', "/api/profesionales/{$this->otroNegocio['ana']->id}/acceso", ['email' => 'compartida@x.com'], $tokenOtra)->assertOk();
        $this->owner('POST', "/api/profesionales/{$this->negocio['ana']->id}/acceso", ['email' => 'compartida@x.com'])->assertOk();
        $cuenta = ProfesionalCuenta::where('email', 'compartida@x.com')->sole();
        $cuenta->createToken('pro-portal', ['pro:cambiar-password']);
        $cuentaAntes = $this->fila('profesional_cuentas', $cuenta->id);
        Mail::fake();

        $delOtro = $this->owner('POST', "/api/profesionales/{$this->otroNegocio['ana']->id}/acceso/reset", [], $tokenOtra);
        $delNuestro = $this->resetear($this->negocio['ana']->id);

        foreach ([$delOtro, $delNuestro] as $respuesta) {
            $respuesta->assertOk()->assertExactJson(['message' => self::MENSAJE_RESET]);
        }
        $this->assertSame($cuentaAntes, $this->fila('profesional_cuentas', $cuenta->id));
        $this->assertSame(1, $cuenta->tokens()->count());
        $this->assertSame(2, $cuenta->vinculos()->count());
        Mail::assertNothingSent();
    }

    public function test_a_disabled_link_in_another_negocio_still_makes_the_account_shared(): void
    {
        $cuenta = $this->cuentaPendiente();
        $this->vincularPortal($cuenta, $this->otroNegocio['ana'], false);
        $antes = $this->fila('profesional_cuentas', $cuenta->id);

        $this->resetear($this->negocio['ana']->id)->assertOk()->assertExactJson(['message' => self::MENSAJE_RESET]);

        $this->assertSame($antes, $this->fila('profesional_cuentas', $cuenta->id));
        Mail::assertNothingSent();
    }

    public function test_after_removing_and_re_inviting_the_account_counts_as_not_created_here(): void
    {
        $this->owner('POST', "/api/profesionales/{$this->negocio['ana']->id}/acceso", ['email' => 'nueva@x.com'])->assertOk();
        $cuenta = ProfesionalCuenta::where('email', 'nueva@x.com')->sole();
        $cuenta->forceFill(['debe_cambiar_password' => false])->save();
        $this->estado($this->negocio['ana']->id)->assertJsonPath('pendiente_de_activacion', false);

        $this->quitar($this->negocio['ana']->id)->assertOk();
        $this->owner('POST', "/api/profesionales/{$this->negocio['ana']->id}/acceso", ['email' => 'nueva@x.com'])->assertOk();

        // The account now pre-exists the new link: it looks pending to the titular, like any pre-existing one.
        $this->estado($this->negocio['ana']->id)->assertJsonPath('pendiente_de_activacion', true);
        $this->assertFalse(ProfesionalVinculo::sole()->cuenta_creada_aqui);
        // And even if it is pending again, it is not hers to reset.
        $cuenta->forceFill(['debe_cambiar_password' => true])->save();
        Mail::fake();
        $antes = $this->fila('profesional_cuentas', $cuenta->id);
        $this->resetear($this->negocio['ana']->id)->assertOk()->assertExactJson(['message' => self::MENSAJE_RESET]);
        $this->assertSame($antes, $this->fila('profesional_cuentas', $cuenta->id));
        Mail::assertNothingSent();
    }

    public function test_a_reset_racing_a_link_delete_changes_nothing_and_sends_nothing(): void
    {
        $cuenta = $this->cuentaPendiente();
        $cuenta->createToken('pro-portal', ['pro:cambiar-password']);
        $antes = $this->fila('profesional_cuentas', $cuenta->id);
        // The link is removed right after the controller read it, before the reset takes its lock.
        $this->alPrimerSelectDe('profesional_vinculos', fn () => DB::table('profesional_vinculos')
            ->where('profesional_id', $this->negocio['ana']->id)->delete());

        $this->resetear($this->negocio['ana']->id)
            ->assertOk()
            ->assertExactJson(['message' => self::MENSAJE_RESET]);

        $this->assertSame($antes, $this->fila('profesional_cuentas', $cuenta->id));
        $this->assertSame(1, $cuenta->tokens()->count());
        $this->assertSame(0, ProfesionalVinculo::count());
        Mail::assertNothingSent();
    }

    public function test_a_reset_racing_the_first_login_changes_nothing_and_sends_nothing(): void
    {
        $cuenta = $this->cuentaPendiente();
        $antes = $this->fila('profesional_cuentas', $cuenta->id);
        $this->alPrimerSelectDe('profesional_vinculos', fn () => DB::table('profesional_cuentas')
            ->where('id', $cuenta->id)->update(['debe_cambiar_password' => false]));

        $this->resetear($this->negocio['ana']->id)->assertOk()->assertExactJson(['message' => self::MENSAJE_RESET]);

        $despues = $this->fila('profesional_cuentas', $cuenta->id);
        $this->assertSame($antes['password'], $despues['password']);
        $this->assertSame(0, (int) $despues['debe_cambiar_password']);
        Mail::assertNothingSent();
    }

    public function test_a_reset_racing_another_negocio_linking_the_account_changes_nothing_and_sends_nothing(): void
    {
        $cuenta = $this->cuentaPendiente();
        $cuenta->createToken('pro-portal', ['pro:cambiar-password']);
        $antes = $this->fila('profesional_cuentas', $cuenta->id);
        $this->alPrimerSelectDe('profesional_vinculos', fn () => DB::table('profesional_vinculos')->insert([
            'cuenta_id' => $cuenta->id,
            'profesional_id' => $this->otroNegocio['ana']->id,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->resetear($this->negocio['ana']->id)->assertOk()->assertExactJson(['message' => self::MENSAJE_RESET]);

        $this->assertSame($antes, $this->fila('profesional_cuentas', $cuenta->id));
        $this->assertSame(1, $cuenta->tokens()->count());
        Mail::assertNothingSent();
    }

    // ── Voseo ───────────────────────────────────────────────────────────────

    public function test_every_user_facing_message_of_the_owner_endpoints_uses_voseo(): void
    {
        $invitar = fn (int $id, string $email) => $this->owner('POST', "/api/profesionales/{$id}/acceso", ['email' => $email]);
        $ana = $this->negocio['ana']->id;

        $mensajes = [
            'sin email' => $this->owner('POST', "/api/profesionales/{$ana}/acceso", [])->json('errors.email.0'),
            'email invalido' => $invitar($ana, 'no-es-un-email')->json('errors.email.0'),
            'perfil titular' => $invitar($this->negocio['titular']->id, 'x@x.com')->json('message'),
            'email titular' => $invitar($ana, self::EMAIL_TITULAR)->json('message'),
        ];
        $this->negocio['bea']->update(['activo' => false]);
        $mensajes['profesional inactiva'] = $invitar($this->negocio['bea']->id, 'bea@x.com')->json('message');
        $this->negocio['bea']->update(['activo' => true]);
        $mensajes['sin acceso'] = $this->quitar($this->negocio['bea']->id)->json('message');
        $mensajes['no encontrada'] = $this->quitar(999999)->json('message');

        $mensajes['invitacion'] = $invitar($ana, 'ana@x.com')->json('message');
        $mensajes['ya tiene acceso'] = $invitar($ana, 'otra@x.com')->json('message');
        $mensajes['activo faltante'] = $this->owner('PATCH', "/api/profesionales/{$ana}/acceso", [])->json('errors.activo.0');
        $mensajes['desactivado'] = $this->cambiarActivo($ana, false)->json('message');
        $mensajes['activado'] = $this->cambiarActivo($ana, true)->json('message');
        $mensajes['reset'] = $this->resetear($ana)->json('message');
        $mensajes['quitado'] = $this->quitar($ana)->json('message');
        for ($i = 0; $i < 10; $i++) {
            $limite = $invitar($ana, "x{$i}@x.com");
        }
        $mensajes['limite'] = $limite->assertStatus(429)->json('message');

        $this->assertSame('Indicá el email de la persona a invitar.', $mensajes['sin email']);
        $this->assertSame('Ingresá un email válido.', $mensajes['email invalido']);
        $this->assertSame('Ese es el email de la persona titular del negocio. Usá otro email.', $mensajes['email titular']);
        $this->assertSame('Esa profesional está desactivada. Activala antes de invitarla.', $mensajes['profesional inactiva']);
        $this->assertSame('Esa profesional ya tiene acceso al portal. Quitá el acceso actual antes de invitar a otro email.', $mensajes['ya tiene acceso']);
        $this->assertSame('Se alcanzó el máximo de invitaciones por hora. Probá de nuevo más tarde.', $mensajes['limite']);
        foreach ($mensajes as $nombre => $mensaje) {
            $this->assertIsString($mensaje, "no message for: {$nombre}");
            $this->assertDoesNotMatchRegularExpression(
                '/\b(Indica|Ingresa|Usa|Actívala|Quita|Prueba|Elige|Revisa|Intenta|Verifica|Escribe|Espera|Vuelve)\b|\b(puedes|tienes|quieres|necesitas)\b/u',
                $mensaje,
                "tuteo in '{$nombre}': {$mensaje}",
            );
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Endurecimiento del login/sesion del admin: timing, desactivacion, auditoria
 * de eventos de autenticacion y cambio de contraseña.
 */
class AdminAuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function crearAdmin(string $password = 'password-seguro-123', bool $activo = true): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Superadmin',
            'email' => 'admin@turnetto.app',
            'password' => Hash::make($password),
        ]);

        if (! $activo) {
            $admin->activo = false;
            $admin->save();
        }

        return $admin;
    }

    private function loginDesde(string $ip, string $email, string $password)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/admin/login', ['email' => $email, 'password' => $password]);
    }

    // ── A1 ──────────────────────────────────────────────────────────

    public function test_email_inexistente_igual_ejecuta_hash_check_y_responde_el_mismo_401(): void
    {
        $this->crearAdmin();
        $existente = $this->loginDesde('10.1.0.1', 'admin@turnetto.app', 'mal');

        Hash::spy();
        $inexistente = $this->loginDesde('10.1.0.2', 'no-existe@turnetto.app', 'mal');

        Hash::shouldHaveReceived('check')->once();
        $inexistente->assertStatus(401);
        $this->assertSame($existente->json(), $inexistente->json());
    }

    // ── A2 (integracion HTTP) ───────────────────────────────────────

    public function test_un_atacante_en_otra_ip_no_puede_bloquear_al_admin_real(): void
    {
        $this->crearAdmin();

        for ($i = 0; $i < 3; $i++) {
            $this->loginDesde('10.1.1.1', 'admin@turnetto.app', 'mal')->assertStatus(401);
        }
        $this->loginDesde('10.1.1.1', 'admin@turnetto.app', 'password-seguro-123')->assertStatus(429);

        $this->loginDesde('10.1.1.2', 'admin@turnetto.app', 'password-seguro-123')->assertOk();
    }

    // ── A3 ──────────────────────────────────────────────────────────

    public function test_admin_inactivo_no_puede_loguearse_y_recibe_el_mismo_401(): void
    {
        $this->crearAdmin(activo: false);

        $inactivo = $this->loginDesde('10.1.2.1', 'admin@turnetto.app', 'password-seguro-123');
        $inexistente = $this->loginDesde('10.1.2.2', 'no-existe@turnetto.app', 'password-seguro-123');

        $inactivo->assertStatus(401);
        $this->assertSame($inexistente->json(), $inactivo->json());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_token_emitido_antes_de_desactivar_deja_de_funcionar_y_se_borra(): void
    {
        $admin = $this->crearAdmin();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/me')
            ->assertOk();

        $admin->activo = false;
        $admin->save();

        // El guard cachea el usuario dentro del mismo proceso de test; en
        // produccion cada request arranca de cero.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/whatsapp/uso-por-salon')
            ->assertStatus(401);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_comando_desactivar_marca_inactivo_y_borra_tokens(): void
    {
        $admin = $this->crearAdmin();
        $admin->createToken('admin-panel');

        $this->artisan('admin:desactivar', ['email' => 'ADMIN@turnetto.app'])->assertExitCode(0);

        $this->assertFalse($admin->fresh()->activo);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_comando_desactivar_con_reactivar_vuelve_a_activar(): void
    {
        $admin = $this->crearAdmin(activo: false);

        $this->artisan('admin:desactivar', ['email' => 'admin@turnetto.app', '--reactivar' => true])
            ->assertExitCode(0);

        $this->assertTrue($admin->fresh()->activo);
        $this->loginDesde('10.1.3.1', 'admin@turnetto.app', 'password-seguro-123')->assertOk();
    }

    public function test_comando_desactivar_falla_si_el_admin_no_existe(): void
    {
        $this->artisan('admin:desactivar', ['email' => 'nadie@turnetto.app'])->assertExitCode(1);
    }

    // ── A5 ──────────────────────────────────────────────────────────

    public function test_login_exitoso_se_audita_con_admin_e_ip(): void
    {
        $admin = $this->crearAdmin();

        $this->loginDesde('10.1.4.1', 'admin@turnetto.app', 'password-seguro-123')->assertOk();

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'login.exitoso',
            'ip' => '10.1.4.1',
        ]);
    }

    public function test_login_fallido_se_audita_sin_email_ni_password_y_marca_si_el_email_existia(): void
    {
        $this->crearAdmin();

        $this->loginDesde('10.1.5.1', 'admin@turnetto.app', 'clave-tipeada-mal');
        $this->loginDesde('10.1.5.2', 'fantasma@turnetto.app', 'otra-clave-mal');

        $logs = AdminAuditLog::where('action', 'login.fallido')->orderBy('id')->get();

        $this->assertCount(2, $logs);
        $this->assertNull($logs[0]->admin_user_id);
        $this->assertSame('10.1.5.1', $logs[0]->ip);
        $this->assertTrue($logs[0]->payload['email_existe']);
        $this->assertFalse($logs[1]->payload['email_existe']);

        $crudo = json_encode($logs->toArray());
        foreach (['admin@turnetto', 'fantasma', 'clave-tipeada-mal', 'otra-clave-mal'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $crudo);
        }
    }

    public function test_login_bloqueado_se_audita(): void
    {
        $this->crearAdmin();

        for ($i = 0; $i < 3; $i++) {
            $this->loginDesde('10.1.6.1', 'admin@turnetto.app', 'mal');
        }
        $this->loginDesde('10.1.6.1', 'admin@turnetto.app', 'mal')->assertStatus(429);

        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'login.bloqueado', 'ip' => '10.1.6.1']);
    }

    public function test_logout_se_audita(): void
    {
        $admin = $this->crearAdmin();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/logout')
            ->assertOk();

        $this->assertDatabaseHas('admin_audit_logs', ['admin_user_id' => $admin->id, 'action' => 'logout']);
    }

    public function test_el_log_de_login_fallido_no_contiene_el_email_tipeado(): void
    {
        $this->crearAdmin();
        Log::spy();

        $this->loginDesde('10.1.7.1', 'admin@turnetto.app', 'mal');

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($mensaje, $contexto = []) => ! str_contains(json_encode($contexto), 'turnetto'),
        )->atLeast()->once();
        Log::shouldNotHaveReceived('warning', [
            \Mockery::any(),
            \Mockery::on(fn ($contexto) => str_contains(json_encode($contexto), 'turnetto')),
        ]);
    }

    // ── A4: POST admin/cambiar-password ─────────────────────────────

    public function test_cambiar_password_actualiza_borra_los_otros_tokens_y_conserva_el_actual(): void
    {
        $admin = $this->crearAdmin();
        $otro = $admin->createToken('otro-dispositivo');
        $actual = $admin->createToken('admin-panel');

        $this->withHeader('Authorization', "Bearer {$actual->plainTextToken}")
            ->postJson('/api/admin/cambiar-password', [
                'current_password' => 'password-seguro-123',
                'password' => 'nueva-password-segura-456',
                'password_confirmation' => 'nueva-password-segura-456',
            ])->assertOk();

        $this->assertTrue(Hash::check('nueva-password-segura-456', $admin->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otro->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $actual->accessToken->id]);
        $this->assertDatabaseHas('admin_audit_logs', ['admin_user_id' => $admin->id, 'action' => 'password.cambiada']);
    }

    public function test_cambiar_password_rechaza_password_actual_incorrecta(): void
    {
        $admin = $this->crearAdmin();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/cambiar-password', [
                'current_password' => 'no-es-esta',
                'password' => 'nueva-password-segura-456',
                'password_confirmation' => 'nueva-password-segura-456',
            ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password-seguro-123', $admin->fresh()->password));
    }

    public function test_cambiar_password_exige_minimo_12_confirmacion_y_distinta_de_la_actual(): void
    {
        $admin = $this->crearAdmin();
        $token = $admin->createToken('admin-panel')->plainTextToken;
        $h = ['Authorization' => "Bearer {$token}"];

        $this->withHeaders($h)->postJson('/api/admin/cambiar-password', [
            'current_password' => 'password-seguro-123',
            'password' => 'corta-11-ch',
            'password_confirmation' => 'corta-11-ch',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->withHeaders($h)->postJson('/api/admin/cambiar-password', [
            'current_password' => 'password-seguro-123',
            'password' => 'nueva-password-segura-456',
            'password_confirmation' => 'distinta-confirmacion',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->withHeaders($h)->postJson('/api/admin/cambiar-password', [
            'current_password' => 'password-seguro-123',
            'password' => 'password-seguro-123',
            'password_confirmation' => 'password-seguro-123',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('password-seguro-123', $admin->fresh()->password));
    }

    public function test_cambiar_password_requiere_autenticacion(): void
    {
        $this->postJson('/api/admin/cambiar-password', [])->assertStatus(401);
    }
}

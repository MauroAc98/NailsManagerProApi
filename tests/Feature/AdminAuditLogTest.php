<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use App\Services\AdminAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'name' => 'Superadmin',
            'email' => 'admin@turnetto.app',
            'password' => 'password-de-test',
        ]);
    }

    public function test_record_persiste_admin_accion_objetivo_y_payload(): void
    {
        $user = User::factory()->create();

        $log = AdminAudit::record($this->admin, 'negocio.creado', $user->id, ['slug' => $user->slug]);

        $this->assertDatabaseHas('admin_audit_logs', [
            'id' => $log->id,
            'admin_user_id' => $this->admin->id,
            'action' => 'negocio.creado',
            'target_user_id' => $user->id,
        ]);
        $this->assertNotNull($log->fresh()->created_at);
        $this->assertSame(['slug' => $user->slug], $log->fresh()->payload);
    }

    public function test_target_user_id_es_opcional(): void
    {
        $log = AdminAudit::record($this->admin, 'login.exitoso');

        $this->assertNull($log->target_user_id);
    }

    // Regresión al borrarse el admin: el historial de auditoría no puede
    // desaparecer solo porque se elimina la cuenta que lo generó.
    public function test_admin_user_id_queda_null_si_se_borra_el_admin(): void
    {
        $log = AdminAudit::record($this->admin, 'login.exitoso');

        $this->admin->delete();

        $this->assertNull($log->fresh()->admin_user_id);
        $this->assertDatabaseHas('admin_audit_logs', ['id' => $log->id]);
    }

    // Spec admin-api-boundary — Scenario "Renewal is audited".
    public function test_renovacion_de_suscripcion_genera_registro_de_auditoria(): void
    {
        $user = User::factory()->create();
        $user->subscription()->create([
            'ends_at' => now()->subDays(5),
            'status' => 'VENCIDO',
        ]);

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/renew")
            ->assertOk();

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $this->admin->id,
            'action' => 'suscripcion.renovada',
            'target_user_id' => $user->id,
        ]);
    }

    // Spec admin-api-boundary — Scenario "Creation is audited" — cubierto en
    // AdminCrearNegocioTest (necesita POST admin/negocios, que llega junto
    // con crearNegocio() en el work unit de provisioning).
}

<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReactivateSubscriptionTest extends TestCase
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

    public function test_requiere_sesion_admin(): void
    {
        $user = User::factory()->create();
        $user->subscription()->create(['ends_at' => now()->addDays(10), 'status' => 'SUSPENDIDO']);

        $this->postJson("/api/admin/subscriptions/{$user->id}/reactivate")
            ->assertUnauthorized();
    }

    public function test_404_cuando_el_usuario_no_tiene_suscripcion(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/reactivate")
            ->assertStatus(404);
    }

    public function test_409_cuando_no_esta_suspendida(): void
    {
        $user = User::factory()->create();
        $user->subscription()->create(['ends_at' => now()->addDays(10), 'status' => 'ACTIVO']);

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/reactivate")
            ->assertStatus(409);

        $this->assertSame(0, AdminAuditLog::where('action', 'suscripcion.reactivada')->count());
    }

    public function test_reactivar_con_ends_at_futuro_vuelve_a_activo_sin_tocar_ends_at(): void
    {
        $user = User::factory()->create();
        $subscription = $user->subscription()->create(['ends_at' => now()->addDays(10), 'status' => 'SUSPENDIDO']);
        $endsAtOriginal = $subscription->ends_at->timestamp;

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/reactivate")
            ->assertOk()
            ->assertJson(['user_id' => $user->id, 'status' => 'ACTIVO'])
            ->assertJsonStructure(['message', 'user_id', 'status', 'ends_at']);

        $fresh = $subscription->fresh();
        $this->assertSame('ACTIVO', $fresh->status);
        $this->assertSame($endsAtOriginal, $fresh->ends_at->timestamp);

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $this->admin->id,
            'action' => 'suscripcion.reactivada',
            'target_user_id' => $user->id,
        ]);
    }

    public function test_reactivar_con_ends_at_pasado_vuelve_a_vencido_sin_tocar_ends_at(): void
    {
        $user = User::factory()->create();
        $subscription = $user->subscription()->create(['ends_at' => now()->subDays(3), 'status' => 'SUSPENDIDO']);
        $endsAtOriginal = $subscription->ends_at->timestamp;

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/reactivate")
            ->assertOk()
            ->assertJson(['user_id' => $user->id, 'status' => 'VENCIDO']);

        $fresh = $subscription->fresh();
        $this->assertSame('VENCIDO', $fresh->status);
        $this->assertSame($endsAtOriginal, $fresh->ends_at->timestamp);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'suscripcion.reactivada',
            'target_user_id' => $user->id,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSuspendSubscriptionTest extends TestCase
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
        $user->subscription()->create(['ends_at' => now()->addDays(10), 'status' => 'ACTIVO']);

        $this->postJson("/api/admin/subscriptions/{$user->id}/suspend")
            ->assertUnauthorized();
    }

    public function test_404_cuando_el_usuario_no_tiene_suscripcion(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/suspend")
            ->assertStatus(404);

        $this->assertDatabaseMissing('admin_audit_logs', [
            'action' => 'suscripcion.suspendida',
            'target_user_id' => $user->id,
        ]);
    }

    public function test_409_cuando_ya_esta_suspendida_y_no_duplica_auditoria(): void
    {
        $user = User::factory()->create();
        $user->subscription()->create(['ends_at' => now()->addDays(10), 'status' => 'SUSPENDIDO']);

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/suspend")
            ->assertStatus(409);

        $this->assertSame(0, \App\Models\AdminAuditLog::where('action', 'suscripcion.suspendida')->count());
    }

    public function test_suspende_una_suscripcion_activa_sin_tocar_ends_at_y_audita(): void
    {
        $user = User::factory()->create();
        $subscription = $user->subscription()->create(['ends_at' => now()->addDays(10), 'status' => 'ACTIVO']);
        $endsAtOriginal = $subscription->ends_at->timestamp;

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/suspend")
            ->assertOk()
            ->assertJson([
                'user_id' => $user->id,
                'status' => 'SUSPENDIDO',
            ])
            ->assertJsonStructure(['message', 'user_id', 'status', 'ends_at']);

        $fresh = $subscription->fresh();
        $this->assertSame('SUSPENDIDO', $fresh->status);
        $this->assertSame($endsAtOriginal, $fresh->ends_at->timestamp);

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $this->admin->id,
            'action' => 'suscripcion.suspendida',
            'target_user_id' => $user->id,
        ]);
    }
}

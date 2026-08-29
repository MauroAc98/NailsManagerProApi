<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminAdjustExpiryTest extends TestCase
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

        $this->postJson("/api/admin/subscriptions/{$user->id}/adjust-expiry", ['ends_at' => '2030-06-15'])
            ->assertUnauthorized();
    }

    public function test_404_cuando_el_usuario_no_tiene_suscripcion(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/adjust-expiry", ['ends_at' => '2030-06-15'])
            ->assertStatus(404);

        $this->assertDatabaseMissing('admin_audit_logs', [
            'action' => 'suscripcion.expiracion_ajustada',
            'target_user_id' => $user->id,
        ]);
    }

    public function test_422_cuando_falta_ends_at_y_no_toca_la_suscripcion(): void
    {
        $user = User::factory()->create();
        $subscription = $user->subscription()->create(['ends_at' => now()->addDays(10), 'status' => 'ACTIVO']);
        $endsAtOriginal = $subscription->ends_at->timestamp;

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/adjust-expiry", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_at');

        $this->assertSame($endsAtOriginal, $subscription->fresh()->ends_at->timestamp);
        $this->assertSame(0, AdminAuditLog::where('action', 'suscripcion.expiracion_ajustada')->count());
    }

    public function test_422_cuando_ends_at_es_invalido(): void
    {
        $user = User::factory()->create();
        $user->subscription()->create(['ends_at' => now()->addDays(10), 'status' => 'ACTIVO']);

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/adjust-expiry", ['ends_at' => 'no-es-una-fecha'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_ajusta_a_fecha_futura_guarda_end_of_day_exacto_sin_drift_y_audita(): void
    {
        $user = User::factory()->create();
        $user->subscription()->create(['ends_at' => now()->subDays(3), 'status' => 'VENCIDO']);

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/adjust-expiry", ['ends_at' => '2030-06-15'])
            ->assertOk()
            ->assertJson(['user_id' => $user->id, 'status' => 'ACTIVO'])
            ->assertJsonStructure(['message', 'user_id', 'status', 'ends_at']);

        // Lectura cruda: cero conversión de timezone. El valor guardado es
        // exactamente el fin del día pedido en America/Argentina/Buenos_Aires.
        $stored = DB::table('subscriptions')->where('user_id', $user->id)->value('ends_at');
        $this->assertSame('2030-06-15 23:59:59', $stored);

        $this->assertSame('ACTIVO', DB::table('subscriptions')->where('user_id', $user->id)->value('status'));

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $this->admin->id,
            'action' => 'suscripcion.expiracion_ajustada',
            'target_user_id' => $user->id,
        ]);
    }

    public function test_ajusta_a_fecha_pasada_marca_vencido_y_corta_el_acceso_del_negocio(): void
    {
        $user = User::factory()->create(['is_exempt' => false]);
        $user->subscription()->create(['ends_at' => now()->addDays(30), 'status' => 'ACTIVO']);

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/adjust-expiry", ['ends_at' => '2020-01-10'])
            ->assertOk()
            ->assertJson(['user_id' => $user->id, 'status' => 'VENCIDO']);

        $stored = DB::table('subscriptions')->where('user_id', $user->id)->value('ends_at');
        $this->assertSame('2020-01-10 23:59:59', $stored);
        $this->assertSame('VENCIDO', DB::table('subscriptions')->where('user_id', $user->id)->value('status'));

        // Gate: con ends_at en el pasado el negocio queda cortado.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/gastos')
            ->assertStatus(403)
            ->assertJson(['code' => 'SUBSCRIPTION_EXPIRED']);
    }

    public function test_ajustar_sobre_una_suscripcion_suspendida_cambia_la_fecha_pero_mantiene_suspendido(): void
    {
        $user = User::factory()->create();
        $user->subscription()->create(['ends_at' => now()->addDays(5), 'status' => 'SUSPENDIDO']);

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/adjust-expiry", ['ends_at' => '2029-03-20'])
            ->assertOk()
            ->assertJson(['user_id' => $user->id, 'status' => 'SUSPENDIDO']);

        $stored = DB::table('subscriptions')->where('user_id', $user->id)->value('ends_at');
        $this->assertSame('2029-03-20 23:59:59', $stored);
        $this->assertSame('SUSPENDIDO', DB::table('subscriptions')->where('user_id', $user->id)->value('status'));

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'suscripcion.expiracion_ajustada',
            'target_user_id' => $user->id,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservaOnlineAddonTest extends TestCase
{
    use RefreshDatabase;

    private function negocio(bool $reservaOnline, int $diasSuscripcion = 10): User
    {
        $user = User::factory()->create(['slug' => 'salon-test']);
        $user->forceFill(['reserva_online' => $reservaOnline])->save();
        $user->subscription()->create(['ends_at' => now()->addDays($diasSuscripcion), 'status' => 'ACTIVO']);

        return $user->fresh();
    }

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Superadmin',
            'email' => 'admin@turnetto.app',
            'password' => 'password-de-test',
        ]);
    }

    public function test_activa_solo_con_flag_y_suscripcion_vigente(): void
    {
        $this->assertTrue($this->negocio(true)->reserva_online_activa);
    }

    public function test_no_esta_activa_sin_el_flag(): void
    {
        $this->assertFalse($this->negocio(false)->reserva_online_activa);
    }

    public function test_no_esta_activa_con_suscripcion_vencida_aunque_tenga_el_flag(): void
    {
        $this->assertFalse($this->negocio(true, -1)->reserva_online_activa);
    }

    public function test_exento_con_flag_esta_activo(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $user->forceFill(['reserva_online' => true])->save();

        $this->assertTrue($user->fresh()->reserva_online_activa);
    }

    public function test_endpoints_publicos_dan_404_sin_el_add_on(): void
    {
        $this->negocio(false);

        $this->getJson('/api/public/salon-test/info')->assertNotFound();
        $this->getJson('/api/public/salon-test/servicios')->assertNotFound();
        $this->getJson('/api/public/salon-test/disponibilidad/dias')->assertNotFound();
    }

    public function test_branding_sigue_publico_sin_el_add_on(): void
    {
        $this->negocio(false);

        $this->getJson('/api/public/salon-test/branding')->assertOk();
    }

    public function test_endpoints_publicos_responden_con_el_add_on(): void
    {
        $this->negocio(true);

        $this->getJson('/api/public/salon-test/info')->assertOk();
    }

    public function test_escrituras_publicas_dan_404_sin_el_add_on(): void
    {
        $this->negocio(false);

        $this->postJson('/api/public/salon-test/reservas/holds')->assertNotFound();
    }

    public function test_admin_activa_y_desactiva_con_auditoria(): void
    {
        $user = $this->negocio(false);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->putJson("/api/admin/negocios/{$user->id}/reserva-online", ['habilitada' => true])
            ->assertOk()
            ->assertJson(['user_id' => $user->id, 'reserva_online' => true]);

        $this->assertTrue($user->fresh()->reserva_online);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'negocio.reserva_online_habilitada',
            'target_user_id' => $user->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->putJson("/api/admin/negocios/{$user->id}/reserva-online", ['habilitada' => false])
            ->assertOk();

        $this->assertFalse($user->fresh()->reserva_online);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'negocio.reserva_online_deshabilitada',
            'target_user_id' => $user->id,
        ]);
    }

    public function test_admin_endpoint_valida_y_exige_sesion_admin(): void
    {
        $user = $this->negocio(false);

        $this->putJson("/api/admin/negocios/{$user->id}/reserva-online", ['habilitada' => true])
            ->assertUnauthorized();

        $this->actingAs($this->admin(), 'admin')
            ->putJson("/api/admin/negocios/{$user->id}/reserva-online", [])
            ->assertStatus(422);
    }

    public function test_el_negocio_no_puede_activarselo_por_perfil(): void
    {
        $user = $this->negocio(false);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', ['reserva_online' => true]);

        $this->assertFalse($user->fresh()->reserva_online);
    }

    public function test_listado_admin_expone_reserva_online(): void
    {
        $this->negocio(true);

        $this->actingAs($this->admin(), 'admin')
            ->getJson('/api/admin/negocios')
            ->assertOk()
            ->assertJsonPath('0.reserva_online', true);
    }

    public function test_me_expone_reserva_online_activa(): void
    {
        $user = $this->negocio(true);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('reserva_online_activa', true);
    }
}

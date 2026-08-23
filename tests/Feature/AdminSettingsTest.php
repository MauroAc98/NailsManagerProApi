<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
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

    public function test_get_rechaza_sin_sesion_admin(): void
    {
        $this->getJson('/api/admin/settings')->assertStatus(401);
    }

    public function test_get_devuelve_10_por_default_si_no_hay_setting_guardado(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJson(['dias_prueba_default' => 10]);
    }

    public function test_get_devuelve_el_valor_guardado(): void
    {
        Setting::create(['key' => 'dias_prueba_default', 'value' => '15']);

        $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJson(['dias_prueba_default' => 15]);
    }

    public function test_put_rechaza_sin_sesion_admin(): void
    {
        $this->putJson('/api/admin/settings', ['dias_prueba_default' => 7])
            ->assertStatus(401);
    }

    public function test_put_actualiza_el_valor(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->putJson('/api/admin/settings', ['dias_prueba_default' => 7])
            ->assertOk()
            ->assertJson(['dias_prueba_default' => 7]);

        $this->assertSame('7', Setting::get('dias_prueba_default'));
    }

    public function test_put_rechaza_valor_fuera_de_rango(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->putJson('/api/admin/settings', ['dias_prueba_default' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dias_prueba_default');
    }

    public function test_put_queda_auditado(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->putJson('/api/admin/settings', ['dias_prueba_default' => 20])
            ->assertOk();

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $this->admin->id,
            'action' => 'settings.actualizado',
        ]);
    }

    public function test_crear_negocio_usa_el_default_configurado(): void
    {
        Setting::create(['key' => 'dias_prueba_default', 'value' => '20']);

        \Illuminate\Support\Facades\Mail::fake();

        $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/negocios', [
                'name' => 'Nails Studio',
                'email' => 'nueva@estudio.com',
                'profesional_nombre' => 'Fernanda',
                'profesional_apellido' => 'Gómez',
            ])
            ->assertCreated();

        $user = \App\Models\User::where('email', 'nueva@estudio.com')->firstOrFail();

        $this->assertEqualsWithDelta(
            now()->addDays(20)->timestamp,
            $user->subscription->ends_at->timestamp,
            5
        );
    }
}

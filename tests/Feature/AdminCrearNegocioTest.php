<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminCrearNegocioTest extends TestCase
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

        Mail::fake();
    }

    public function test_rechaza_sin_sesion_admin(): void
    {
        $this->postJson('/api/admin/negocios', [
            'name' => 'Nails Studio',
            'email' => 'nueva@estudio.com',
            'profesional_nombre' => 'Fernanda',
            'profesional_apellido' => 'Gómez',
        ])->assertStatus(401);

        $this->assertDatabaseMissing('users', ['email' => 'nueva@estudio.com']);
    }

    public function test_crea_negocio_y_devuelve_nombre_y_slug(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/negocios', [
                'name' => 'Nails Studio',
                'email' => 'nueva@estudio.com',
                'profesional_nombre' => 'Fernanda',
                'profesional_apellido' => 'Gómez',
            ])
            ->assertCreated();

        $user = User::where('email', 'nueva@estudio.com')->firstOrFail();
        $profesional = Profesional::where('user_id', $user->id)->firstOrFail();

        $this->assertSame('Nails Studio', $user->name);
        $this->assertNotEmpty($user->slug);
        $response->assertJsonPath('user.slug', $user->slug);
        $this->assertSame('Fernanda', $profesional->nombre);
        $this->assertSame('Gómez', $profesional->apellido);
    }

    public function test_crea_suscripcion_de_prueba_de_10_dias(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/negocios', [
                'name' => 'Nails Studio',
                'email' => 'nueva@estudio.com',
                'profesional_nombre' => 'Fernanda',
                'profesional_apellido' => 'Gómez',
            ])
            ->assertCreated();

        $user = User::where('email', 'nueva@estudio.com')->firstOrFail();

        $this->assertNotNull($user->subscription);
        $this->assertEqualsWithDelta(
            now()->addDays(10)->timestamp,
            $user->subscription->ends_at->timestamp,
            5
        );
    }

    public function test_email_duplicado_es_rechazado_sin_crear_cuenta_parcial(): void
    {
        User::factory()->create(['email' => 'existente@estudio.com']);

        $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/negocios', [
                'name' => 'Nails Studio',
                'email' => 'existente@estudio.com',
                'profesional_nombre' => 'Fernanda',
                'profesional_apellido' => 'Gómez',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, User::where('email', 'existente@estudio.com')->count());
        $this->assertDatabaseCount('profesionales', 0);
    }

    public function test_sin_profesional_nombre_es_rechazado(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/negocios', [
                'name' => 'Nails Studio',
                'email' => 'nueva@estudio.com',
                'profesional_apellido' => 'Gómez',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('profesional_nombre');

        $this->assertDatabaseMissing('users', ['email' => 'nueva@estudio.com']);
    }

    public function test_sin_profesional_apellido_es_rechazado(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/negocios', [
                'name' => 'Nails Studio',
                'email' => 'nueva@estudio.com',
                'profesional_nombre' => 'Fernanda',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('profesional_apellido');

        $this->assertDatabaseMissing('users', ['email' => 'nueva@estudio.com']);
    }

    // Spec admin-api-boundary — Scenario "Creation is audited".
    public function test_creacion_genera_registro_de_auditoria(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/negocios', [
                'name' => 'Nails Studio',
                'email' => 'nueva@estudio.com',
                'profesional_nombre' => 'Fernanda',
                'profesional_apellido' => 'Gómez',
            ])
            ->assertCreated();

        $user = User::where('email', 'nueva@estudio.com')->firstOrFail();

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $this->admin->id,
            'action' => 'negocio.creado',
            'target_user_id' => $user->id,
        ]);
    }
}

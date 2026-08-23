<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAuthGuardTest extends TestCase
{
    use RefreshDatabase;

    private function crearAdmin(string $password = 'password-seguro'): AdminUser
    {
        return AdminUser::create([
            'name' => 'Superadmin',
            'email' => 'admin@turnetto.app',
            'password' => Hash::make($password),
        ]);
    }

    public function test_rechaza_request_sin_token(): void
    {
        $this->getJson('/api/admin/whatsapp/uso-por-salon')
            ->assertStatus(401);
    }

    public function test_rechaza_token_de_tenant(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('app-mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/whatsapp/uso-por-salon')
            ->assertStatus(401);
    }

    // Legado: X-Admin-Secret ya no alcanza por sí solo — la única vía de
    // acceso es el guard `admin` (Sanctum, provider admin_users).
    public function test_rechaza_solo_el_header_legacy_x_admin_secret(): void
    {
        config(['app.admin_secret' => 'secreto-de-test']);

        $this->withHeader('X-Admin-Secret', 'secreto-de-test')
            ->getJson('/api/admin/whatsapp/uso-por-salon')
            ->assertStatus(401);
    }

    public function test_login_exitoso_emite_token_admin(): void
    {
        $this->crearAdmin('password-seguro');

        $response = $this->postJson('/api/admin/login', [
            'email' => 'admin@turnetto.app',
            'password' => 'password-seguro',
        ])->assertOk();

        $response->assertJsonStructure(['admin', 'token', 'expires_at']);
    }

    public function test_login_con_credenciales_invalidas_es_rechazado(): void
    {
        $this->crearAdmin('password-seguro');

        $this->postJson('/api/admin/login', [
            'email' => 'admin@turnetto.app',
            'password' => 'incorrecta',
        ])->assertStatus(401);

        $this->assertGuest('admin');
    }

    public function test_login_con_email_inexistente_es_rechazado(): void
    {
        $this->postJson('/api/admin/login', [
            'email' => 'no-existe@turnetto.app',
            'password' => 'lo-que-sea',
        ])->assertStatus(401);
    }

    public function test_token_de_admin_autoriza_rutas_admin(): void
    {
        $admin = $this->crearAdmin();

        $this->actingAs($admin, 'admin')
            ->getJson('/api/admin/whatsapp/uso-por-salon')
            ->assertOk();
    }

    // Regresión inversa a la del guard de tenant: un token admin tampoco
    // tiene que abrir rutas de tenant (auth:sanctum, provider users).
    public function test_token_de_admin_no_autoriza_rutas_de_tenant(): void
    {
        $admin = $this->crearAdmin();

        $this->actingAs($admin, 'admin')
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_login_admin_se_limita_por_intentos(): void
    {
        $this->crearAdmin('password-seguro');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/admin/login', [
                'email' => 'admin@turnetto.app',
                'password' => 'incorrecta',
            ]);
        }

        $this->postJson('/api/admin/login', [
            'email' => 'admin@turnetto.app',
            'password' => 'incorrecta',
        ])->assertStatus(429);
    }
}

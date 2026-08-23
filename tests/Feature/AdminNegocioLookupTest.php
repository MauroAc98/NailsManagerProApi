<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNegocioLookupTest extends TestCase
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

    public function test_rechaza_sin_sesion_admin(): void
    {
        $this->getJson('/api/admin/negocios/buscar?q=algo')
            ->assertStatus(401);
    }

    public function test_busca_por_email_exacto(): void
    {
        $user = User::factory()->create(['email' => 'match@estudio.com']);
        $user->subscription()->create(['ends_at' => now()->addDays(10), 'status' => 'ACTIVO']);

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/negocios/buscar?q=match@estudio.com')
            ->assertOk();

        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'id' => $user->id,
            'email' => 'match@estudio.com',
            'slug' => $user->slug,
        ]);
        $response->assertJsonStructure([['id', 'name', 'slug', 'email', 'is_exempt', 'subscription' => ['ends_at', 'status', 'renewed_at']]]);
    }

    // Regresión: status era una columna STORED que nunca se actualizaba
    // cuando ends_at simplemente pasaba (sin job programado). Ahora se
    // computa en vivo, igual que AuthController::subscriptionStatus().
    public function test_negocio_vencido_devuelve_status_vencido_aunque_la_columna_diga_activo(): void
    {
        $user = User::factory()->create(['email' => 'vencido@estudio.com']);
        $user->subscription()->create(['ends_at' => now()->subDays(1), 'status' => 'ACTIVO']);

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/negocios/buscar?q=vencido@estudio.com')
            ->assertOk();

        $response->assertJsonPath('0.subscription.status', 'VENCIDO');
    }

    // El email tiene que matchear completo — un fragmento del email no
    // alcanza (a diferencia del slug, que sí usa LIKE parcial).
    public function test_email_parcial_no_matchea(): void
    {
        User::factory()->create(['email' => 'match@estudio.com']);

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/negocios/buscar?q=match')
            ->assertOk();

        $response->assertJsonCount(0);
    }

    public function test_busca_por_slug_parcial(): void
    {
        $user = User::factory()->create(['name' => 'Nails Studio Uno']);

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/negocios/buscar?q='.substr($user->slug, 0, 5))
            ->assertOk();

        $response->assertJsonFragment(['id' => $user->id]);
    }

    public function test_sin_coincidencias_devuelve_lista_vacia(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/negocios/buscar?q=no-existe-esto')
            ->assertOk();

        $response->assertJsonCount(0);
    }

    public function test_query_vacia_no_devuelve_todos_los_negocios(): void
    {
        User::factory()->create();
        User::factory()->create();

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/negocios/buscar?q=')
            ->assertOk();

        $response->assertJsonCount(0);
    }

    public function test_limita_a_5_resultados(): void
    {
        for ($i = 0; $i < 7; $i++) {
            User::factory()->create(['name' => "Estudio Buscable {$i}"]);
        }

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/negocios/buscar?q=estudio-buscable')
            ->assertOk();

        $response->assertJsonCount(5);
    }
}

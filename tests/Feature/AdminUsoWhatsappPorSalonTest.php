<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUsoWhatsappPorSalonTest extends TestCase
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

    private function crearMensaje(int $userId, string $numero, string $createdAt, string $provider = 'cloud_api'): WhatsappMensaje
    {
        $mensaje = WhatsappMensaje::create([
            'user_id' => $userId,
            'numero' => $numero,
            'provider' => $provider,
            'mensaje' => 'contenido de prueba',
            'tipo' => 'confirmacion',
            'status' => 'pending',
        ]);

        $mensaje->forceFill(['created_at' => $createdAt])->save();

        return $mensaje;
    }

    public function test_rechaza_sin_sesion_admin(): void
    {
        $this->getJson('/api/admin/whatsapp/uso-por-salon')
            ->assertStatus(401);
    }

    public function test_dos_mensajes_al_mismo_numero_dentro_de_24hs_cuentan_como_una_conversacion(): void
    {
        $user = User::factory()->create(['name' => 'Nails Studio']);

        // Borde exacto: 23h59m de diferencia — todavía DENTRO de la ventana.
        $this->crearMensaje($user->id, '5493765000001', '2026-08-05 10:00:00');
        $this->crearMensaje($user->id, '5493765000001', '2026-08-06 09:59:00');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/whatsapp/uso-por-salon?desde=2026-08-01&hasta=2026-08-31')
            ->assertOk();

        $response->assertJsonFragment([
            'user_id' => $user->id,
            'nombre' => 'Nails Studio',
            'mensajes_totales' => 2,
            'conversaciones_estimadas' => 1,
        ]);
    }

    public function test_dos_mensajes_al_mismo_numero_con_mas_de_24hs_de_diferencia_cuentan_como_dos_conversaciones(): void
    {
        $user = User::factory()->create();

        // Borde exacto: 24h01m de diferencia — ya FUERA de la ventana.
        $this->crearMensaje($user->id, '5493765000001', '2026-08-05 10:00:00');
        $this->crearMensaje($user->id, '5493765000001', '2026-08-06 10:01:00');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/whatsapp/uso-por-salon?desde=2026-08-01&hasta=2026-08-31')
            ->assertOk();

        $response->assertJsonFragment([
            'user_id' => $user->id,
            'mensajes_totales' => 2,
            'conversaciones_estimadas' => 2,
        ]);
    }

    public function test_tres_mensajes_dos_juntos_y_uno_treinta_horas_despues_dan_dos_conversaciones(): void
    {
        $user = User::factory()->create();

        $this->crearMensaje($user->id, '5493765000001', '2026-08-05 10:00:00');
        $this->crearMensaje($user->id, '5493765000001', '2026-08-05 10:30:00');
        $this->crearMensaje($user->id, '5493765000001', '2026-08-06 16:00:00'); // 30hs después del inicio de la ventana anterior

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/whatsapp/uso-por-salon?desde=2026-08-01&hasta=2026-08-31')
            ->assertOk();

        $response->assertJsonFragment([
            'user_id' => $user->id,
            'mensajes_totales' => 3,
            'conversaciones_estimadas' => 2,
        ]);
    }

    public function test_mensajes_a_numeros_distintos_suman_conversaciones_por_separado(): void
    {
        $user = User::factory()->create();

        $this->crearMensaje($user->id, '5493765000001', '2026-08-05 10:00:00');
        $this->crearMensaje($user->id, '5493765000002', '2026-08-05 10:05:00');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/whatsapp/uso-por-salon?desde=2026-08-01&hasta=2026-08-31')
            ->assertOk();

        $response->assertJsonFragment([
            'user_id' => $user->id,
            'mensajes_totales' => 2,
            'conversaciones_estimadas' => 2,
        ]);
    }

    public function test_mensajes_de_evolution_no_se_cuentan(): void
    {
        $user = User::factory()->create();

        $this->crearMensaje($user->id, '5493765000001', '2026-08-05 10:00:00', 'evolution');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/whatsapp/uso-por-salon?desde=2026-08-01&hasta=2026-08-31')
            ->assertOk();

        $response->assertJsonCount(0, 'salones');
    }

    public function test_mensaje_fuera_del_rango_de_fechas_no_se_cuenta(): void
    {
        $user = User::factory()->create();

        $this->crearMensaje($user->id, '5493765000001', '2026-07-01 10:00:00');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/whatsapp/uso-por-salon?desde=2026-08-01&hasta=2026-08-31')
            ->assertOk();

        $response->assertJsonCount(0, 'salones');
    }

    public function test_dos_usuarios_distintos_aparecen_por_separado(): void
    {
        $userA = User::factory()->create(['name' => 'Salon A']);
        $userB = User::factory()->create(['name' => 'Salon B']);

        $this->crearMensaje($userA->id, '5493765000001', '2026-08-05 10:00:00');
        $this->crearMensaje($userB->id, '5493765000002', '2026-08-05 10:00:00');
        $this->crearMensaje($userB->id, '5493765000002', '2026-08-05 11:00:00');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/whatsapp/uso-por-salon?desde=2026-08-01&hasta=2026-08-31')
            ->assertOk();

        $response->assertJsonCount(2, 'salones');
        $response->assertJsonFragment([
            'user_id' => $userA->id,
            'mensajes_totales' => 1,
            'conversaciones_estimadas' => 1,
        ]);
        $response->assertJsonFragment([
            'user_id' => $userB->id,
            'mensajes_totales' => 2,
            'conversaciones_estimadas' => 1,
        ]);
    }

    public function test_usuario_sin_mensajes_en_el_rango_no_aparece(): void
    {
        User::factory()->create();

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/whatsapp/uso-por-salon?desde=2026-08-01&hasta=2026-08-31')
            ->assertOk();

        $response->assertJsonCount(0, 'salones');
    }

    public function test_provider_por_defecto_es_evolution(): void
    {
        $user = User::factory()->create();

        $mensaje = WhatsappMensaje::create([
            'user_id' => $user->id,
            'numero' => '5493765000001',
            'mensaje' => 'contenido de prueba',
            'tipo' => 'confirmacion',
            'status' => 'pending',
        ]);

        $this->assertSame('evolution', $mensaje->fresh()->provider);
    }
}

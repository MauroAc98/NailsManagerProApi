<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUsoPorNegocioResumenTest extends TestCase
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

    private function crearTurno(int $userId, string $createdAt): Turno
    {
        $turno = Turno::create([
            'user_id' => $userId,
            'fecha_hora' => $createdAt,
            'duracion_total_minutos' => 30,
            'origen' => 'app',
        ]);

        $turno->forceFill(['created_at' => $createdAt])->save();

        return $turno;
    }

    private function crearMensaje(int $userId, string $tipo, string $status, string $createdAt, ?string $messageId = 'wamid.test'): WhatsappMensaje
    {
        $mensaje = WhatsappMensaje::create([
            'user_id' => $userId,
            'numero' => '5493765000001',
            'provider' => 'cloud_api',
            'mensaje' => 'contenido de prueba',
            'tipo' => $tipo,
            'message_id' => $messageId,
            'status' => $status,
        ]);

        $mensaje->forceFill(['created_at' => $createdAt])->save();

        return $mensaje;
    }

    public function test_rechaza_sin_sesion_admin(): void
    {
        $this->getJson('/api/admin/uso/negocios')
            ->assertStatus(401);
    }

    public function test_cuenta_turnos_confirmaciones_recordatorios_y_fallos_por_negocio(): void
    {
        $user = User::factory()->create(['name' => 'Nails by Caro']);

        $this->crearTurno($user->id, '2026-09-10 10:00:00');
        $this->crearTurno($user->id, '2026-09-11 10:00:00');
        $this->crearMensaje($user->id, 'confirmacion', 'delivered', '2026-09-10 10:01:00');
        $this->crearMensaje($user->id, 'recordatorio', 'delivered', '2026-09-09 09:00:00');
        $this->crearMensaje($user->id, 'confirmacion', 'failed', '2026-09-11 10:01:00');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/uso/negocios?desde=2026-09-01&hasta=2026-09-30')
            ->assertOk();

        $response->assertJsonFragment([
            'user_id' => $user->id,
            'nombre' => 'Nails by Caro',
            'turnos' => 2,
            'confirmaciones' => 2,
            'recordatorios' => 1,
            'fallos' => 1,
        ]);
    }

    public function test_actividad_fuera_del_rango_no_se_cuenta(): void
    {
        $user = User::factory()->create();

        $this->crearTurno($user->id, '2026-08-01 10:00:00');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/uso/negocios?desde=2026-09-01&hasta=2026-09-30')
            ->assertOk();

        $response->assertJsonCount(0, 'negocios');
    }

    public function test_negocio_sin_actividad_en_el_rango_no_aparece(): void
    {
        User::factory()->create();

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/uso/negocios?desde=2026-09-01&hasta=2026-09-30')
            ->assertOk();

        $response->assertJsonCount(0, 'negocios');
    }

    public function test_dos_negocios_aparecen_por_separado(): void
    {
        $userA = User::factory()->create(['name' => 'Salon A']);
        $userB = User::factory()->create(['name' => 'Salon B']);

        $this->crearTurno($userA->id, '2026-09-10 10:00:00');
        $this->crearTurno($userB->id, '2026-09-10 10:00:00');
        $this->crearTurno($userB->id, '2026-09-11 10:00:00');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/uso/negocios?desde=2026-09-01&hasta=2026-09-30')
            ->assertOk();

        $response->assertJsonCount(2, 'negocios');
        $response->assertJsonFragment(['user_id' => $userA->id, 'turnos' => 1]);
        $response->assertJsonFragment(['user_id' => $userB->id, 'turnos' => 2]);
    }

    public function test_sin_rango_explicito_usa_los_ultimos_30_dias(): void
    {
        $user = User::factory()->create();

        $this->crearTurno($user->id, now()->subDays(5)->toDateTimeString());
        $this->crearTurno($user->id, now()->subDays(45)->toDateTimeString());

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/uso/negocios')
            ->assertOk();

        $response->assertJsonFragment(['user_id' => $user->id, 'turnos' => 1]);
    }
}

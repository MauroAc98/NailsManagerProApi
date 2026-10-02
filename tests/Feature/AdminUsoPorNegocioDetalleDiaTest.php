<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUsoPorNegocioDetalleDiaTest extends TestCase
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

    private function crearMensaje(int $userId, string $tipo, string $status, string $createdAt): WhatsappMensaje
    {
        $mensaje = WhatsappMensaje::create([
            'user_id' => $userId,
            'numero' => '5493765000001',
            'provider' => 'cloud_api',
            'mensaje' => 'contenido de prueba',
            'tipo' => $tipo,
            'message_id' => 'wamid.test',
            'status' => $status,
        ]);

        $mensaje->forceFill(['created_at' => $createdAt])->save();

        return $mensaje;
    }

    public function test_rechaza_sin_sesion_admin(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/admin/uso/negocios/{$user->id}/dia?fecha=2026-09-10")
            ->assertStatus(401);
    }

    public function test_requiere_el_parametro_fecha(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin, 'admin')
            ->getJson("/api/admin/uso/negocios/{$user->id}/dia")
            ->assertStatus(422);
    }

    public function test_devuelve_las_24_horas_del_dia_con_las_que_tienen_actividad_completas(): void
    {
        $user = User::factory()->create();

        $this->crearTurno($user->id, '2026-09-10 11:00:00');
        $this->crearTurno($user->id, '2026-09-10 11:30:00');
        $this->crearMensaje($user->id, 'confirmacion', 'delivered', '2026-09-10 11:05:00');
        $this->crearMensaje($user->id, 'recordatorio', 'failed', '2026-09-10 16:00:00');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson("/api/admin/uso/negocios/{$user->id}/dia?fecha=2026-09-10")
            ->assertOk();

        $horas = $response->json('horas');
        $this->assertCount(24, $horas);

        $hora11 = collect($horas)->firstWhere('hora', 11);
        $hora16 = collect($horas)->firstWhere('hora', 16);
        $hora0 = collect($horas)->firstWhere('hora', 0);

        $this->assertSame(2, $hora11['turnos']);
        $this->assertSame(1, $hora11['confirmaciones']);
        $this->assertSame(1, $hora16['fallos']);
        $this->assertSame(['hora' => 0, 'turnos' => 0, 'confirmaciones' => 0, 'recordatorios' => 0, 'fallos' => 0], $hora0);
    }

    public function test_actividad_de_otro_dia_no_se_cuenta(): void
    {
        $user = User::factory()->create();

        $this->crearTurno($user->id, '2026-09-11 11:00:00');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson("/api/admin/uso/negocios/{$user->id}/dia?fecha=2026-09-10")
            ->assertOk();

        $hora11 = collect($response->json('horas'))->firstWhere('hora', 11);
        $this->assertSame(0, $hora11['turnos']);
    }
}

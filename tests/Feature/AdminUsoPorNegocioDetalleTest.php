<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUsoPorNegocioDetalleTest extends TestCase
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

    private function crearMensaje(int $userId, string $tipo, string $status, string $createdAt, array $extra = []): WhatsappMensaje
    {
        $mensaje = WhatsappMensaje::create(array_merge([
            'user_id' => $userId,
            'numero' => '5493765000001',
            'provider' => 'cloud_api',
            'mensaje' => 'contenido de prueba',
            'tipo' => $tipo,
            'message_id' => 'wamid.test',
            'status' => $status,
        ], $extra));

        $mensaje->forceFill(['created_at' => $createdAt])->save();

        return $mensaje;
    }

    public function test_rechaza_sin_sesion_admin(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/admin/uso/negocios/{$user->id}")
            ->assertStatus(401);
    }

    public function test_agrupa_actividad_por_dia_y_completa_dias_sin_actividad_en_cero(): void
    {
        $user = User::factory()->create(['name' => 'Nails by Caro']);

        $this->crearTurno($user->id, '2026-09-10 10:00:00');
        $this->crearTurno($user->id, '2026-09-10 15:00:00');
        $this->crearMensaje($user->id, 'confirmacion', 'delivered', '2026-09-10 10:01:00');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson("/api/admin/uso/negocios/{$user->id}?desde=2026-09-09&hasta=2026-09-11")
            ->assertOk();

        $dias = $response->json('dias');
        $this->assertCount(3, $dias); // 9, 10, 11 de sep — incluye días sin actividad

        $dia9 = collect($dias)->firstWhere('fecha', '2026-09-09');
        $dia10 = collect($dias)->firstWhere('fecha', '2026-09-10');

        $this->assertSame(['fecha' => '2026-09-09', 'turnos' => 0, 'confirmaciones' => 0, 'recordatorios' => 0, 'fallos' => 0], $dia9);
        $this->assertSame(2, $dia10['turnos']);
        $this->assertSame(1, $dia10['confirmaciones']);
    }

    public function test_totales_suman_toda_la_actividad_del_rango(): void
    {
        $user = User::factory()->create();

        $this->crearTurno($user->id, '2026-09-10 10:00:00');
        $this->crearTurno($user->id, '2026-09-11 10:00:00');
        $this->crearMensaje($user->id, 'recordatorio', 'delivered', '2026-09-11 09:00:00');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson("/api/admin/uso/negocios/{$user->id}?desde=2026-09-01&hasta=2026-09-30")
            ->assertOk();

        $response->assertJsonFragment([
            'totales' => ['turnos' => 2, 'confirmaciones' => 0, 'recordatorios' => 1, 'fallos' => 0],
        ]);
    }

    public function test_fallo_con_message_id_se_marca_origen_meta_y_trae_el_motivo_real(): void
    {
        $user = User::factory()->create();

        $this->crearMensaje($user->id, 'recordatorio', 'failed', '2026-09-10 10:00:00', [
            'error_code' => 131026,
            'error_titulo' => 'Message undeliverable',
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson("/api/admin/uso/negocios/{$user->id}?desde=2026-09-01&hasta=2026-09-30")
            ->assertOk();

        $response->assertJsonFragment([
            'tipo' => 'recordatorio',
            'origen' => 'meta',
            'motivo' => 'Message undeliverable',
            'codigo' => 131026,
        ]);
    }

    public function test_fallo_sin_message_id_se_marca_origen_nuestro_lado(): void
    {
        $user = User::factory()->create();

        $this->crearMensaje($user->id, 'confirmacion', 'failed', '2026-09-10 10:00:00', [
            'message_id' => null,
            'status_code' => 500,
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson("/api/admin/uso/negocios/{$user->id}?desde=2026-09-01&hasta=2026-09-30")
            ->assertOk();

        $response->assertJsonFragment([
            'tipo' => 'confirmacion',
            'origen' => 'nuestro',
            'codigo' => 500,
        ]);
    }

    public function test_solo_trae_actividad_del_negocio_pedido(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->crearTurno($userA->id, '2026-09-10 10:00:00');
        $this->crearTurno($userB->id, '2026-09-10 10:00:00');
        $this->crearTurno($userB->id, '2026-09-10 11:00:00');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson("/api/admin/uso/negocios/{$userA->id}?desde=2026-09-01&hasta=2026-09-30")
            ->assertOk();

        $response->assertJsonFragment(['totales' => ['turnos' => 1, 'confirmaciones' => 0, 'recordatorios' => 0, 'fallos' => 0]]);
    }

    public function test_ultimo_turno_epoch_es_el_instante_exacto_all_time(): void
    {
        $user = User::factory()->create();
        $otro = User::factory()->create();

        $this->crearTurno($user->id, '2026-03-01 08:00:00');
        $this->crearTurno($user->id, '2026-03-01 11:30:15');
        $this->crearTurno($otro->id, '2026-08-01 11:30:15');

        $esperado = Carbon::parse('2026-03-01 11:30:15', config('app.timezone'))->timestamp;

        $this->actingAs($this->admin, 'admin')
            ->getJson("/api/admin/uso/negocios/{$user->id}?desde=2026-09-01&hasta=2026-09-30")
            ->assertOk()
            ->assertJsonPath('ultimo_turno_epoch', $esperado);
    }

    public function test_ultimo_turno_epoch_es_null_sin_turnos(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin, 'admin')
            ->getJson("/api/admin/uso/negocios/{$user->id}?desde=2026-09-01&hasta=2026-09-30")
            ->assertOk()
            ->assertJsonPath('ultimo_turno_epoch', null);
    }

    public function test_fallos_recientes_traen_epoch_exacto_y_hasta_50(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 55; $i++) {
            $this->crearMensaje($user->id, 'recordatorio', 'failed', '2026-09-10 10:00:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        $this->crearMensaje($user->id, 'confirmacion', 'failed', '2026-09-20 15:45:30');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson("/api/admin/uso/negocios/{$user->id}?desde=2026-09-01&hasta=2026-09-30")
            ->assertOk();

        $response->assertJsonCount(50, 'fallos_recientes');
        $response->assertJsonPath('fallos_recientes.0.fecha', '2026-09-20');
        $response->assertJsonPath(
            'fallos_recientes.0.epoch',
            Carbon::parse('2026-09-20 15:45:30', config('app.timezone'))->timestamp
        );
    }
}

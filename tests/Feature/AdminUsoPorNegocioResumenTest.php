<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Subscription;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Carbon\Carbon;
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

    // Negocio con cuenta vigente (suscripcion ACTIVO a futuro) — el resumen
    // lista a todos los que no estan vencidos.
    private function crearNegocio(array $attrs = []): User
    {
        $user = User::factory()->create($attrs);

        Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(10),
            'status' => 'ACTIVO',
        ]);

        return $user;
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
        $user = $this->crearNegocio(['name' => 'Nails by Caro']);

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

    public function test_actividad_fuera_del_rango_no_se_cuenta_en_los_contadores(): void
    {
        $user = $this->crearNegocio();

        $this->crearTurno($user->id, '2026-08-01 10:00:00');

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/uso/negocios?desde=2026-09-01&hasta=2026-09-30')
            ->assertOk();

        $response->assertJsonCount(1, 'negocios');
        $response->assertJsonFragment(['user_id' => $user->id, 'turnos' => 0]);
    }

    public function test_negocio_sin_actividad_en_el_rango_aparece_si_la_cuenta_no_esta_vencida(): void
    {
        $user = $this->crearNegocio();

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/uso/negocios?desde=2026-09-01&hasta=2026-09-30')
            ->assertOk();

        $response->assertJsonCount(1, 'negocios');
        $response->assertJsonFragment([
            'user_id' => $user->id,
            'turnos' => 0,
            'confirmaciones' => 0,
            'recordatorios' => 0,
            'fallos' => 0,
            'ultimo_turno_epoch' => null,
        ]);
    }

    public function test_cuenta_exenta_aparece_aunque_no_tenga_suscripcion(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_exempt' => true])->save();

        $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/uso/negocios?desde=2026-09-01&hasta=2026-09-30')
            ->assertOk()
            ->assertJsonFragment(['user_id' => $user->id]);
    }

    public function test_cuentas_vencidas_suspendidas_o_sin_suscripcion_no_aparecen(): void
    {
        $vencido = User::factory()->create();
        Subscription::create(['user_id' => $vencido->id, 'ends_at' => now()->subDay(), 'status' => 'ACTIVO']);
        $suspendido = User::factory()->create();
        Subscription::create(['user_id' => $suspendido->id, 'ends_at' => now()->addDays(10), 'status' => 'SUSPENDIDO']);
        $sinSuscripcion = User::factory()->create();
        // Aunque hayan tenido actividad en el rango, siguen fuera.
        $this->crearTurno($vencido->id, '2026-09-10 10:00:00');
        $this->crearTurno($suspendido->id, '2026-09-10 10:00:00');
        $this->crearTurno($sinSuscripcion->id, '2026-09-10 10:00:00');

        $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/uso/negocios?desde=2026-09-01&hasta=2026-09-30')
            ->assertOk()
            ->assertJsonCount(0, 'negocios');
    }

    public function test_ultimo_turno_epoch_es_el_instante_exacto_y_no_se_limita_al_rango(): void
    {
        $user = $this->crearNegocio();

        // Zona de la app (no UTC): un epoch correcto no puede salir corrido ~3h.
        $this->crearTurno($user->id, '2026-03-01 08:00:00');
        $this->crearTurno($user->id, '2026-03-01 11:30:15');

        $esperado = Carbon::parse('2026-03-01 11:30:15', config('app.timezone'))->timestamp;

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/uso/negocios?desde=2026-09-01&hasta=2026-09-30')
            ->assertOk();

        $this->assertSame($esperado, $response->json('negocios.0.ultimo_turno_epoch'));
        $response->assertJsonFragment(['user_id' => $user->id, 'turnos' => 0]);
    }

    public function test_orden_sin_turnos_primero_luego_el_ultimo_turno_mas_viejo_y_empate_por_nombre(): void
    {
        $reciente = $this->crearNegocio(['name' => 'Reciente']);
        $viejoB = $this->crearNegocio(['name' => 'Viejo B']);
        $viejoA = $this->crearNegocio(['name' => 'Viejo A']);
        $this->crearNegocio(['name' => 'Nunca']);

        $this->crearTurno($reciente->id, '2026-09-20 10:00:00');
        $this->crearTurno($viejoB->id, '2026-01-05 10:00:00');
        $this->crearTurno($viejoA->id, '2026-01-05 10:00:00');

        $nombres = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/uso/negocios?desde=2026-09-01&hasta=2026-09-30')
            ->assertOk()
            ->json('negocios.*.nombre');

        $this->assertSame(['Nunca', 'Viejo A', 'Viejo B', 'Reciente'], $nombres);
    }

    public function test_dos_negocios_aparecen_por_separado(): void
    {
        $userA = $this->crearNegocio(['name' => 'Salon A']);
        $userB = $this->crearNegocio(['name' => 'Salon B']);

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
        $user = $this->crearNegocio();

        $this->crearTurno($user->id, now()->subDays(5)->toDateTimeString());
        $this->crearTurno($user->id, now()->subDays(45)->toDateTimeString());

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/uso/negocios')
            ->assertOk();

        $response->assertJsonFragment(['user_id' => $user->id, 'turnos' => 1]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordatoriosPendientesEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurnoDeManana(User $user, string $telefono = '3765252395'): Turno
    {
        return $this->crearTurno($user, now()->addDay()->setTime(10, 0), $telefono);
    }

    private function crearTurno(User $user, $fechaHora, string $telefono = '3765252395'): Turno
    {
        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => $telefono,
        ]);

        return Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => $fechaHora,
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
    }

    // Con teléfono cargado y locale='es', ninguno de los 3 criterios de
    // whatsapp_requiere_envio_manual se cumple (ver User::criterioRequiereEnvioManualWhatsapp) —
    // representa el ~99% de cuentas con envío automático habilitado, el
    // caso que antes no tenía ninguna forma de enterarse de un fallo.
    private function crearUsuarioAutomatico(): User
    {
        return User::factory()->create([
            'is_exempt' => true,
            'telefono' => '3765000000',
            'locale' => 'es',
        ]);
    }

    private function crearMensaje(User $user, Turno $turno, string $tipo, string $status): WhatsappMensaje
    {
        return WhatsappMensaje::create([
            'user_id' => $user->id,
            'turno_id' => $turno->id,
            'numero' => $turno->cliente?->telefono ?? '',
            'provider' => 'cloud_api',
            'mensaje' => 'Hola',
            'tipo' => $tipo,
            'status' => $status,
        ]);
    }

    // ── GET /api/turnos/recordatorios-pendientes ──────────────────

    public function test_turno_de_manana_sin_recordatorio_aparece_en_el_listado(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurnoDeManana($user);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/recordatorios-pendientes')
            ->assertOk();

        $response->assertJsonFragment(['id' => $turno->id]);
    }

    public function test_turno_con_recordatorio_ya_registrado_no_aparece(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurnoDeManana($user);

        WhatsappMensaje::create([
            'user_id' => $user->id,
            'turno_id' => $turno->id,
            'numero' => '3765252395',
            'mensaje' => 'Hola',
            'tipo' => 'recordatorio',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/recordatorios-pendientes')
            ->assertOk();

        $response->assertJsonMissing(['id' => $turno->id]);
        $response->assertJsonCount(0);
    }

    public function test_turno_con_cliente_sin_telefono_no_aparece(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $this->crearTurnoDeManana($user, telefono: '');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/recordatorios-pendientes')
            ->assertOk();

        $response->assertJsonCount(0);
    }

    // ── Caso 2: envíos automáticos fallidos (cualquier cuenta) ────

    public function test_turno_con_confirmacion_fallida_aparece_para_cuenta_automatica(): void
    {
        $user = $this->crearUsuarioAutomatico();
        $turno = $this->crearTurno($user, now()->addHours(3));
        $this->crearMensaje($user, $turno, 'confirmacion', 'failed');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/recordatorios-pendientes')
            ->assertOk();

        $response->assertJsonFragment(['id' => $turno->id, 'confirmacion_whatsapp_status' => 'failed']);
    }

    public function test_turno_con_recordatorio_fallido_aparece_para_cuenta_automatica(): void
    {
        $user = $this->crearUsuarioAutomatico();
        $turno = $this->crearTurno($user, now()->addDays(2));
        $this->crearMensaje($user, $turno, 'recordatorio', 'failed');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/recordatorios-pendientes')
            ->assertOk();

        $response->assertJsonFragment(['id' => $turno->id, 'recordatorio_whatsapp_status' => 'failed']);
    }

    // Regresión: una cuenta automática que ya está siendo consultada por
    // este endpoint (antes solo lo consultaban las cuentas manuales) no
    // debe llenarse de falsos positivos con envíos que sí funcionaron.
    public function test_recordatorio_automatico_exitoso_no_aparece_para_cuenta_automatica(): void
    {
        $user = $this->crearUsuarioAutomatico();
        $turno = $this->crearTurno($user, now()->addDay()->setTime(10, 0));
        $this->crearMensaje($user, $turno, 'recordatorio', 'delivered');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/recordatorios-pendientes')
            ->assertOk();

        $response->assertJsonMissing(['id' => $turno->id]);
        $response->assertJsonCount(0);
    }

    // El caso 1 ("todavía no hay ningún recordatorio para este turno de
    // mañana") solo tiene sentido para cuentas whatsapp_requiere_envio_manual —
    // para una cuenta automática, que el recordatorio todavía no se haya
    // mandado no es, por sí solo, un problema.
    public function test_turno_de_manana_sin_recordatorio_no_aparece_para_cuenta_automatica(): void
    {
        $user = $this->crearUsuarioAutomatico();
        $this->crearTurno($user, now()->addDay()->setTime(10, 0));

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/recordatorios-pendientes')
            ->assertOk();

        $response->assertJsonCount(0);
    }

    public function test_turno_vencido_con_mensaje_fallido_no_aparece(): void
    {
        $user = $this->crearUsuarioAutomatico();
        $turno = $this->crearTurno($user, now()->subDay());
        $this->crearMensaje($user, $turno, 'confirmacion', 'failed');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/recordatorios-pendientes')
            ->assertOk();

        $response->assertJsonCount(0);
    }

    // ── POST /api/turnos/{id}/recordatorio-manual ─────────────────

    public function test_marca_recordatorio_manual_crea_whatsapp_mensaje(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurnoDeManana($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/turnos/{$turno->id}/recordatorio-manual")
            ->assertOk();

        $this->assertDatabaseHas('whatsapp_mensajes', [
            'turno_id' => $turno->id,
            'tipo' => 'recordatorio',
            'status' => 'manual',
        ]);
        $this->assertSame(
            1,
            WhatsappMensaje::where('turno_id', $turno->id)->count(),
        );
    }

    public function test_marcar_dos_veces_no_duplica_el_registro(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurnoDeManana($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/turnos/{$turno->id}/recordatorio-manual")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/turnos/{$turno->id}/recordatorio-manual")
            ->assertOk();

        $this->assertSame(
            1,
            WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'recordatorio')->count(),
        );
    }

    public function test_no_se_puede_marcar_un_turno_de_otro_usuario(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);
        $turnoAjeno = $this->crearTurnoDeManana($otroUsuario);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/turnos/{$turnoAjeno->id}/recordatorio-manual")
            ->assertNotFound();
    }

    // Documenta el shape real de la excepción que dispara la carrera
    // exists()+create() no atómica en marcarRecordatorioManual: dos
    // WhatsappMensaje con el mismo (turno_id, tipo) violan el unique
    // constraint de la migración 2026_07_02_123237. No simula concurrencia
    // real (dos requests en simultáneo) — valida contra el driver de test
    // (sqlite) que el mensaje de la QueryException es detectable con el
    // mismo criterio (`str_contains` case-insensitive de "unique") que usa
    // el catch del controller, para que ese catch no quede sin cobertura
    // de que realmente reconoce esta excepción.
    public function test_duplicado_de_turno_id_y_tipo_lanza_query_exception_de_unique_constraint(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurnoDeManana($user);

        $datosMensaje = [
            'user_id' => $user->id,
            'turno_id' => $turno->id,
            'numero' => '3765252395',
            'mensaje' => '',
            'tipo' => 'recordatorio',
            'status' => 'manual',
        ];

        WhatsappMensaje::create($datosMensaje);

        try {
            WhatsappMensaje::create($datosMensaje);
            $this->fail('Se esperaba una QueryException por violación de unique constraint.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertTrue(str_contains(strtolower($e->getMessage()), 'unique'));
        }
    }

    // ── POST /api/turnos/{id}/confirmacion-manual ──────────────────

    public function test_marca_confirmacion_manual_crea_whatsapp_mensaje(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurnoDeManana($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/turnos/{$turno->id}/confirmacion-manual")
            ->assertOk();

        $this->assertDatabaseHas('whatsapp_mensajes', [
            'turno_id' => $turno->id,
            'tipo' => 'confirmacion',
            'status' => 'manual',
        ]);
        $this->assertSame(
            1,
            WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'confirmacion')->count(),
        );
    }

    public function test_marcar_confirmacion_dos_veces_no_duplica_el_registro(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurnoDeManana($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/turnos/{$turno->id}/confirmacion-manual")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/turnos/{$turno->id}/confirmacion-manual")
            ->assertOk();

        $this->assertSame(
            1,
            WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'confirmacion')->count(),
        );
    }

    public function test_no_se_puede_marcar_confirmacion_de_un_turno_de_otro_usuario(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);
        $turnoAjeno = $this->crearTurnoDeManana($otroUsuario);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/turnos/{$turnoAjeno->id}/confirmacion-manual")
            ->assertNotFound();
    }
}

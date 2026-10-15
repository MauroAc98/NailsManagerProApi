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
        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => $telefono,
        ]);

        return Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addDay()->setTime(10, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
    }

    private function crearRecordatorio(User $user, Turno $turno, string $status, ?string $messageId): WhatsappMensaje
    {
        return WhatsappMensaje::create([
            'user_id' => $user->id,
            'turno_id' => $turno->id,
            'numero' => '3765252395',
            'mensaje' => 'Hola',
            'tipo' => 'recordatorio',
            'message_id' => $messageId,
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

    public function test_recordatorio_fallido_con_message_id_sigue_pendiente_para_mandarlo_a_mano(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurnoDeManana($user);
        $this->crearRecordatorio($user, $turno, 'failed', 'wamid.ABC');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/recordatorios-pendientes')
            ->assertOk()
            ->assertJsonFragment(['id' => $turno->id]);
    }

    public function test_recordatorio_fallido_sin_message_id_no_aparece_porque_es_un_error_nuestro(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurnoDeManana($user);
        $this->crearRecordatorio($user, $turno, 'failed', null);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/recordatorios-pendientes')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_recordatorio_entregado_leido_o_manual_no_aparece(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $entregado = $this->crearTurnoDeManana($user, '3765000001');
        $leido = $this->crearTurnoDeManana($user, '3765000002');
        $manual = $this->crearTurnoDeManana($user, '3765000003');
        $this->crearRecordatorio($user, $entregado, 'delivered', 'wamid.1');
        $this->crearRecordatorio($user, $leido, 'read', 'wamid.2');
        $this->crearRecordatorio($user, $manual, 'manual', null);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/recordatorios-pendientes')
            ->assertOk()
            ->assertJsonCount(0);
    }

    // ── POST /api/turnos/{id}/recordatorio-manual ─────────────────

    public function test_marcar_manual_un_recordatorio_fallido_reenviable_lo_pasa_a_manual_sin_duplicar(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurnoDeManana($user);
        $this->crearRecordatorio($user, $turno, 'failed', 'wamid.ABC');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/turnos/{$turno->id}/recordatorio-manual")
            ->assertOk();

        $this->assertDatabaseHas('whatsapp_mensajes', ['turno_id' => $turno->id, 'tipo' => 'recordatorio', 'status' => 'manual']);
        $this->assertSame(1, WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'recordatorio')->count());

        // Ya gestionado: deja de estar pendiente.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/recordatorios-pendientes')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_marcar_manual_no_toca_un_fallo_sin_message_id_ni_un_mensaje_entregado(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $nuestro = $this->crearTurnoDeManana($user, '3765000001');
        $entregado = $this->crearTurnoDeManana($user, '3765000002');
        $this->crearRecordatorio($user, $nuestro, 'failed', null);
        $this->crearRecordatorio($user, $entregado, 'delivered', 'wamid.1');

        foreach ([$nuestro, $entregado] as $t) {
            $this->actingAs($user, 'sanctum')->postJson("/api/turnos/{$t->id}/recordatorio-manual")->assertOk();
        }

        $this->assertDatabaseHas('whatsapp_mensajes', ['turno_id' => $nuestro->id, 'tipo' => 'recordatorio', 'status' => 'failed']);
        $this->assertDatabaseHas('whatsapp_mensajes', ['turno_id' => $entregado->id, 'tipo' => 'recordatorio', 'status' => 'delivered']);
    }

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
}

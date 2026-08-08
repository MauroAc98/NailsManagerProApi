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
            'intentos' => 1,
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
            'intentos' => 1,
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

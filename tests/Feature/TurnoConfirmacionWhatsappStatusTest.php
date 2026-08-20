<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnoConfirmacionWhatsappStatusTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurno(User $user): Turno
    {
        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '3765252395',
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

    // ── GET /api/turnos ─────────────────────────────────────────

    public function test_index_expone_el_status_del_ultimo_mensaje_de_confirmacion(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user);

        WhatsappMensaje::create([
            'user_id' => $user->id,
            'turno_id' => $turno->id,
            'numero' => '3765252395',
            'mensaje' => 'Hola',
            'tipo' => 'confirmacion',
            'status' => 'delivered',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos')
            ->assertOk();

        $response->assertJsonFragment(['id' => $turno->id, 'confirmacion_whatsapp_status' => 'delivered']);
    }

    public function test_index_devuelve_null_cuando_no_hay_mensaje_de_confirmacion(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos')
            ->assertOk();

        $response->assertJsonFragment(['id' => $turno->id, 'confirmacion_whatsapp_status' => null]);
    }

    public function test_index_no_expone_la_relacion_cruda_whatsapp_mensajes(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user);

        WhatsappMensaje::create([
            'user_id' => $user->id,
            'turno_id' => $turno->id,
            'numero' => '3765252395',
            'mensaje' => 'Hola',
            'tipo' => 'confirmacion',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos')
            ->assertOk();

        $response->assertJsonMissingPath('0.whatsapp_mensajes');
        $response->assertJsonMissingPath('0.whatsappMensajes');
    }

    public function test_index_ignora_mensajes_de_tipo_recordatorio(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user);

        WhatsappMensaje::create([
            'user_id' => $user->id,
            'turno_id' => $turno->id,
            'numero' => '3765252395',
            'mensaje' => 'Hola',
            'tipo' => 'recordatorio',
            'status' => 'manual',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos')
            ->assertOk();

        $response->assertJsonFragment(['id' => $turno->id, 'confirmacion_whatsapp_status' => null]);
    }

    // ── GET /api/turnos/{id} ─────────────────────────────────────

    public function test_show_expone_el_status_del_mensaje_de_confirmacion(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user);

        WhatsappMensaje::create([
            'user_id' => $user->id,
            'turno_id' => $turno->id,
            'numero' => '3765252395',
            'mensaje' => 'Hola',
            'tipo' => 'confirmacion',
            'status' => 'read',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/turnos/{$turno->id}")
            ->assertOk();

        $response->assertJson(['confirmacion_whatsapp_status' => 'read']);
    }

    public function test_show_devuelve_null_cuando_no_hay_mensaje_de_confirmacion(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/turnos/{$turno->id}")
            ->assertOk();

        $response->assertJson(['confirmacion_whatsapp_status' => null]);
    }

    public function test_show_no_expone_la_relacion_cruda_whatsapp_mensajes(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user);

        WhatsappMensaje::create([
            'user_id' => $user->id,
            'turno_id' => $turno->id,
            'numero' => '3765252395',
            'mensaje' => 'Hola',
            'tipo' => 'confirmacion',
            'status' => 'failed',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/turnos/{$turno->id}")
            ->assertOk();

        $response->assertJsonMissingPath('whatsapp_mensajes');
        $response->assertJsonMissingPath('whatsappMensajes');
    }
}

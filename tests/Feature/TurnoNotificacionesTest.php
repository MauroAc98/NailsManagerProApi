<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnoNotificacionesTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurno(User $user, string $nombreCliente = 'Martina'): Turno
    {
        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => $nombreCliente,
            'apellido' => 'Diaz',
            'telefono' => '+543765123456',
        ]);

        return Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addHours(2),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
    }

    public function test_devuelve_mensajes_de_hoy_con_cliente_y_status(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user, 'Martina');

        WhatsappMensaje::create([
            'user_id' => $user->id,
            'turno_id' => $turno->id,
            'numero' => '3765123456',
            'provider' => 'cloud_api',
            'mensaje' => 'x',
            'tipo' => 'confirmacion',
            'message_id' => 'wamid.1',
            'status' => 'delivered',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/notificaciones')
            ->assertOk();

        $response->assertJsonCount(1, 'mensajes');
        $response->assertJsonPath('mensajes.0.tipo', 'confirmacion');
        $response->assertJsonPath('mensajes.0.status', 'delivered');
        $response->assertJsonPath('mensajes.0.cliente_nombre', 'Martina');
        $response->assertJsonPath('mensajes.0.cliente_apellido', 'Diaz');
    }

    public function test_no_devuelve_mensajes_de_otro_dia(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user);

        $mensajeViejo = WhatsappMensaje::create([
            'user_id' => $user->id,
            'turno_id' => $turno->id,
            'numero' => '3765123456',
            'provider' => 'cloud_api',
            'mensaje' => 'x',
            'tipo' => 'confirmacion',
            'message_id' => 'wamid.2',
            'status' => 'delivered',
        ]);
        $mensajeViejo->created_at = now()->subDay();
        $mensajeViejo->save();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/notificaciones')
            ->assertOk();

        $response->assertJsonCount(0, 'mensajes');
    }

    public function test_no_devuelve_mensajes_de_otro_usuario(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUser = User::factory()->create(['is_exempt' => true]);
        $turnoDeOtro = $this->crearTurno($otroUser);

        WhatsappMensaje::create([
            'user_id' => $otroUser->id,
            'turno_id' => $turnoDeOtro->id,
            'numero' => '3765123456',
            'provider' => 'cloud_api',
            'mensaje' => 'x',
            'tipo' => 'confirmacion',
            'message_id' => 'wamid.3',
            'status' => 'delivered',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/notificaciones')
            ->assertOk();

        $response->assertJsonCount(0, 'mensajes');
    }

    public function test_incluye_el_conteo_de_turnos_confirmados_de_manana(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '+543765111111',
        ]);

        Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addDay()->setTime(10, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        // Cancelado -> no debe contar.
        Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addDay()->setTime(11, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'cancelado',
            'origen' => 'app',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/notificaciones')
            ->assertOk();

        $response->assertJsonPath('turnos_manana', 1);
    }
}

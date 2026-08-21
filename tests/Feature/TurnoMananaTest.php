<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnoMananaTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurnoDeManana(User $user, string $nombreCliente = 'Martina'): Turno
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
            'fecha_hora' => now()->addDay()->setTime(10, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
    }

    public function test_incluye_turnos_de_manana_aunque_ya_tengan_recordatorio_enviado(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurnoDeManana($user);

        WhatsappMensaje::create([
            'user_id' => $user->id,
            'turno_id' => $turno->id,
            'numero' => '3765123456',
            'provider' => 'cloud_api',
            'mensaje' => 'x',
            'tipo' => 'recordatorio',
            'message_id' => 'wamid.1',
            'status' => 'delivered',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/manana')
            ->assertOk();

        $response->assertJsonCount(1);
        $response->assertJsonPath('0.recordatorio_status', 'delivered');
        $response->assertJsonPath('0.cliente.nombre', 'Martina');
    }

    public function test_recordatorio_status_null_cuando_todavia_no_se_mando(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $this->crearTurnoDeManana($user);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/manana')
            ->assertOk();

        $response->assertJsonPath('0.recordatorio_status', null);
    }

    public function test_no_incluye_turnos_cancelados(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $cliente = Cliente::create([
            'user_id' => $user->id, 'nombre' => 'Ana', 'apellido' => 'Gomez', 'telefono' => '+543765111111',
        ]);
        Turno::create([
            'user_id' => $user->id, 'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addDay()->setTime(11, 0), 'duracion_total_minutos' => 60,
            'estado' => 'cancelado', 'origen' => 'app',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/manana')
            ->assertOk();

        $response->assertJsonCount(0);
    }

    public function test_no_incluye_turnos_de_otro_dia(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $cliente = Cliente::create([
            'user_id' => $user->id, 'nombre' => 'Ana', 'apellido' => 'Gomez', 'telefono' => '+543765111111',
        ]);
        Turno::create([
            'user_id' => $user->id, 'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addDays(2)->setTime(11, 0), 'duracion_total_minutos' => 60,
            'estado' => 'confirmado', 'origen' => 'app',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/manana')
            ->assertOk();

        $response->assertJsonCount(0);
    }

    public function test_no_incluye_turnos_de_otro_usuario(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUser = User::factory()->create(['is_exempt' => true]);
        $this->crearTurnoDeManana($otroUser);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos/manana')
            ->assertOk();

        $response->assertJsonCount(0);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * whatsapp_ultimo_status: status of the latest whatsapp_mensajes row of ANY
 * tipo for a turno. confirmacion_whatsapp_status (latest confirmacion only)
 * must keep working next to it.
 */
class TurnoWhatsappUltimoStatusTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurno(User $user, int $horas = 10): Turno
    {
        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '37652523' . $horas, // unique per user
        ]);

        return Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addDay()->setTime($horas, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
    }

    private function mensaje(User $user, Turno $turno, string $tipo, string $status, string $creadoHace = '0 minutes'): WhatsappMensaje
    {
        $m = WhatsappMensaje::create([
            'user_id' => $user->id,
            'turno_id' => $turno->id,
            'numero' => '3765252395',
            'mensaje' => 'Hola',
            'tipo' => $tipo,
            'status' => $status,
        ]);
        $m->created_at = now()->sub($creadoHace);
        $m->save();

        return $m;
    }

    /** 4 turnos, each with its own messages, to prove nothing leaks between parents. */
    private function escenario(User $user): array
    {
        $t1 = $this->crearTurno($user, 9);
        $this->mensaje($user, $t1, 'confirmacion', 'pending', '3 hours');
        $this->mensaje($user, $t1, 'recordatorio', 'read', '1 hour');

        $t2 = $this->crearTurno($user, 10);
        $this->mensaje($user, $t2, 'confirmacion', 'delivered', '2 hours');
        $this->mensaje($user, $t2, 'reprogramacion', 'failed', '30 minutes');

        $t3 = $this->crearTurno($user, 11);
        $this->mensaje($user, $t3, 'confirmacion', 'sent', '4 hours');
        $this->mensaje($user, $t3, 'recordatorio', 'delivered', '6 hours'); // older than the confirmaciones

        $t4 = $this->crearTurno($user, 12); // no messages

        return [$t1, $t2, $t3, $t4];
    }

    private function esperados(array $turnos): array
    {
        [$t1, $t2, $t3, $t4] = $turnos;

        return [
            $t1->id => ['pending', 'read'],
            $t2->id => ['delivered', 'failed'],
            $t3->id => ['sent', 'sent'],
            $t4->id => [null, null],
        ];
    }

    private function assertPorTurno(array $filas, array $turnos): void
    {
        $porId = collect($filas)->keyBy('id');
        foreach ($this->esperados($turnos) as $id => [$confirmacion, $ultimo]) {
            $this->assertSame($confirmacion, $porId[$id]['confirmacion_whatsapp_status'], "confirmacion_whatsapp_status of turno {$id}");
            $this->assertSame($ultimo, $porId[$id]['whatsapp_ultimo_status'], "whatsapp_ultimo_status of turno {$id}");
            $this->assertArrayNotHasKey('ultimo_whatsapp_mensaje', $porId[$id]);
            $this->assertArrayNotHasKey('whatsapp_mensajes', $porId[$id]);
        }
    }

    public function test_index_resuelve_cada_status_por_turno(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turnos = $this->escenario($user);

        $json = $this->actingAs($user, 'sanctum')->getJson('/api/turnos')->assertOk()->json();

        $this->assertCount(4, $json);
        $this->assertPorTurno($json, $turnos);
    }

    public function test_cobros_resuelve_cada_status_por_turno(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turnos = $this->escenario($user);

        $json = $this->actingAs($user, 'sanctum')->getJson('/api/cobros')->assertOk()->json('data');

        $this->assertCount(4, $json);
        $this->assertPorTurno($json, $turnos);
    }

    public function test_show_expone_el_ultimo_status_de_cualquier_tipo(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turnos = $this->escenario($user);

        foreach ($this->esperados($turnos) as $id => [$confirmacion, $ultimo]) {
            $res = $this->actingAs($user, 'sanctum')->getJson("/api/turnos/{$id}")->assertOk();
            $res->assertJsonPath('confirmacion_whatsapp_status', $confirmacion);
            $res->assertJsonPath('whatsapp_ultimo_status', $ultimo);
            $res->assertJsonMissingPath('ultimo_whatsapp_mensaje');
            $res->assertJsonMissingPath('whatsapp_mensajes');
        }
    }

    public function test_confirmacion_pendiente_y_recordatorio_leido_devuelve_read(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user);
        $this->mensaje($user, $turno, 'confirmacion', 'pending', '2 hours');
        $this->mensaje($user, $turno, 'recordatorio', 'read', '1 hour');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos')
            ->assertOk()
            ->assertJsonFragment(['id' => $turno->id, 'confirmacion_whatsapp_status' => 'pending', 'whatsapp_ultimo_status' => 'read']);
    }

    public function test_con_igual_created_at_gana_el_de_mayor_id(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user);
        $a = $this->mensaje($user, $turno, 'confirmacion', 'pending', '1 hour');
        $b = $this->mensaje($user, $turno, 'recordatorio', 'delivered', '1 hour');
        $b->created_at = $a->created_at;
        $b->save();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/turnos/{$turno->id}")
            ->assertOk()
            ->assertJsonPath('whatsapp_ultimo_status', 'delivered');
    }

    public function test_devuelve_null_cuando_no_hay_mensajes(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/turnos')
            ->assertOk()
            ->assertJsonFragment(['id' => $turno->id, 'whatsapp_ultimo_status' => null]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnviarRecordatoriosDeduplicacionTest extends TestCase
{
    use RefreshDatabase;

    // Escenario real: la profesional mandó el recordatorio a mano desde
    // /agenda/recordatorios a la mañana (crea un WhatsappMensaje
    // status='manual'), y el flag whatsapp_requiere_envio_manual se cura
    // ese mismo día antes de que corra el cron horario en hora_recordatorio.
    // El comando entra por la rama NORMAL (flag ya en false) — sin el fix,
    // vuelve a mandar el mismo recordatorio: duplicado real al cliente, y
    // el WhatsappMensaje::create() posterior explota contra el unique
    // constraint (turno_id, tipo), quedando logueado como fallido pese a
    // que en realidad ya estaba resuelto.
    public function test_no_reenvia_recordatorio_automatico_para_un_turno_ya_gestionado(): void
    {
        Http::fake();

        $user = User::factory()->create([
            'is_exempt' => true,
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
            'telefono' => '3765000000',
        ]);

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '3765252395',
        ]);

        $turno = Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addDay()->setTime(10, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        WhatsappMensaje::create([
            'user_id' => $user->id,
            'turno_id' => $turno->id,
            'numero' => '3765252395',
            'mensaje' => '',
            'tipo' => 'recordatorio',
            'status' => 'manual',
        ]);

        $this->artisan('recordatorios:enviar');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendText'));

        $this->assertSame(
            1,
            WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'recordatorio')->count(),
        );
    }
}

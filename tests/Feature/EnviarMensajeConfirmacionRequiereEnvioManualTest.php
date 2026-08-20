<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappEstadoHistorial;
use App\Models\WhatsappMensaje;
use App\Services\EvolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnviarMensajeConfirmacionRequiereEnvioManualTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_envia_confirmacion_cuando_requiere_envio_manual(): void
    {
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']], 200),
            '*/message/sendText/*' => Http::response(['key' => ['id' => 'ABC123']], 200),
        ]);

        // whatsapp_estado='conectado' e instancia seteada — aísla que el skip
        // es por whatsapp_requiere_envio_manual y no por tieneWhatsappConectado().
        $user = User::factory()->create([
            'is_exempt' => true,
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        $historial = WhatsappEstadoHistorial::create([
            'user_id' => $user->id,
            'estado' => 'desconectado',
            'status_reason' => 401,
        ]);
        $historial->created_at = now()->subDay();
        $historial->save();

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '3765252395',
        ]);

        $turno = Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addHours(2),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(EvolutionService::class), app(\App\Services\CloudApiService::class));

        Http::assertNothingSent();

        $this->assertSame(
            0,
            WhatsappMensaje::where('turno_id', $turno->id)->count(),
        );
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappEstadoHistorial;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReenviarMensajesPendientesRequiereEnvioManualTest extends TestCase
{
    use RefreshDatabase;

    public function test_omite_reintento_sin_quemarlo_cuando_requiere_envio_manual(): void
    {
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']], 200),
            '*/message/sendText/*' => Http::response(['key' => ['id' => 'ABC123']], 200),
        ]);

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

        $ultimoIntentoOriginal = now()->subMinutes(10);

        $registro = WhatsappMensaje::create([
            'user_id' => $user->id,
            'numero' => '5493765252395',
            'mensaje' => 'Hola, tu turno está confirmado.',
            'tipo' => 'confirmacion',
            'status' => 'failed',
            'intentos' => 1,
            'ultimo_intento' => $ultimoIntentoOriginal,
        ]);

        $this->artisan('whatsapp:reenviar-pendientes');

        Http::assertNothingSent();

        $registro->refresh();
        $this->assertSame('failed', $registro->status);
        $this->assertSame(1, $registro->intentos);
        // El timestamp de la columna no guarda microsegundos — comparar por
        // segundo evita un falso negativo por precisión, no por lógica real.
        $this->assertSame(
            $ultimoIntentoOriginal->toDateTimeString(),
            $registro->ultimo_intento->toDateTimeString(),
        );
    }
}

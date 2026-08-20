<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Services\CloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnviarMensajeConfirmacionRequiereEnvioManualTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_envia_confirmacion_cuando_requiere_envio_manual(): void
    {
        Http::fake();

        // Sin teléfono cargado -> whatsapp_requiere_envio_manual = true.
        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => null,
        ]);

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '+543765252395',
        ]);

        $turno = Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addHours(2),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertNothingSent();

        $this->assertSame(
            0,
            WhatsappMensaje::where('turno_id', $turno->id)->count(),
        );
    }
}

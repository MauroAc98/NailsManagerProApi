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

class EnviarMensajeConfirmacionRespuestaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_persiste_status_code_y_respuesta_api_en_un_envio_exitoso(): void
    {
        config([
            'services.whatsapp_cloud.token' => 'fake-token',
            'services.whatsapp_cloud.phone_number_id' => '123456',
            'services.whatsapp_cloud.api_version' => 'v26.0',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.XYZ789']],
            ], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '+543765111111',
            'direccion' => 'San Martin 123',
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

        $registro = WhatsappMensaje::where('turno_id', $turno->id)->first();

        $this->assertNotNull($registro);
        $this->assertSame(200, $registro->status_code);
        $this->assertSame(['messages' => [['id' => 'wamid.XYZ789']]], $registro->respuesta_api);
    }

    public function test_persiste_status_code_y_respuesta_api_cuando_meta_rechaza(): void
    {
        config([
            'services.whatsapp_cloud.token' => 'fake-token',
            'services.whatsapp_cloud.phone_number_id' => '123456',
            'services.whatsapp_cloud.api_version' => 'v26.0',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid parameter']], 400),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '+543765111111',
            'direccion' => 'San Martin 123',
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

        $registro = WhatsappMensaje::where('turno_id', $turno->id)->first();

        $this->assertNotNull($registro);
        $this->assertSame('failed', $registro->status);
        $this->assertSame(400, $registro->status_code);
        $this->assertSame(['error' => ['message' => 'Invalid parameter']], $registro->respuesta_api);
    }
}

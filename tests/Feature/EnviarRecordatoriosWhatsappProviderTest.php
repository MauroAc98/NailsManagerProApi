<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnviarRecordatoriosWhatsappProviderTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurnoDeManana(User $user): Turno
    {
        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '3765252395',
        ]);

        $servicio = Servicio::create([
            'user_id' => $user->id,
            'nombre' => 'Manicura',
            'duracion_minutos' => 60,
            'precio' => 5000,
            'activo' => true,
        ]);

        $turno = Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addDay()->setTime(10, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        $turno->servicios()->attach($servicio->id);

        return $turno;
    }

    public function test_cuenta_cloud_api_sin_instancia_evolution_recibe_recordatorio_por_cloud_api(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.XYZ']]], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
            'whatsapp_provider' => 'cloud_api',
            'evolution_instance_name' => null,
        ]);

        $turno = $this->crearTurnoDeManana($user);

        $this->artisan('recordatorios:enviar');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));

        $this->assertSame(
            1,
            WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'recordatorio')->where('status', 'pending')->count(),
        );
    }

    public function test_cuenta_evolution_sigue_el_camino_de_siempre(): void
    {
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']], 200),
            '*/message/sendText/*' => Http::response(['key' => ['id' => 'ABC123']], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        $turno = $this->crearTurnoDeManana($user);

        $this->artisan('recordatorios:enviar');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/message/sendText/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));

        $this->assertSame(
            1,
            WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'recordatorio')->where('status', 'pending')->count(),
        );
    }
}

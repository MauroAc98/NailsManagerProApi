<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Cliente;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Services\CloudApiService;
use App\Services\EvolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnviarMensajeConfirmacionWhatsappProviderTest extends TestCase
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
            'fecha_hora' => now()->addHours(2),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        $turno->servicios()->attach($servicio->id);

        return $turno;
    }

    public function test_usa_cloud_api_cuando_el_provider_es_cloud_api_y_locale_espanol(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.XYZ']]], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'whatsapp_provider' => 'cloud_api',
            'locale' => 'es',
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(EvolutionService::class), app(CloudApiService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/message/sendText/')
            || str_contains($request->url(), '/instance/connectionState/'));

        $this->assertSame(
            1,
            WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'confirmacion')->where('status', 'pending')->count(),
        );
    }

    public function test_usa_evolution_cuando_el_provider_es_evolution_default(): void
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

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(EvolutionService::class), app(CloudApiService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/message/sendText/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_cae_a_evolution_cuando_provider_es_cloud_api_pero_locale_pt_br(): void
    {
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']], 200),
            '*/message/sendText/*' => Http::response(['key' => ['id' => 'ABC123']], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'whatsapp_provider' => 'cloud_api',
            'locale' => 'pt-BR',
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(EvolutionService::class), app(CloudApiService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/message/sendText/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EvolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvolutionServiceGenerarQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_desloguea_una_instancia_recien_creada(): void
    {
        Http::fake([
            '*/instance/create' => Http::response(['instance' => ['instanceName' => 'user_1']], 201),
            '*/instance/connect/*' => Http::response(['base64' => 'data:image/png;base64,abc'], 200),
        ]);

        $user = User::factory()->create([
            'evolution_instance_name' => null,
            'whatsapp_estado' => 'desconectado',
        ]);

        app(EvolutionService::class)->generarQr($user);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/instance/logout/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/instance/create'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/instance/connect/'));
    }

    public function test_desloguea_una_instancia_existente_antes_de_pedir_qr_nuevo(): void
    {
        Http::fake([
            '*/instance/logout/*' => Http::response([], 200),
            '*/instance/connect/*' => Http::response(['base64' => 'data:image/png;base64,abc'], 200),
        ]);

        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'desconectado',
        ]);

        app(EvolutionService::class)->generarQr($user);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/instance/logout/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/instance/connect/'));
    }

    public function test_no_desloguea_de_nuevo_en_un_refresh_del_mismo_intento(): void
    {
        Http::fake([
            '*/instance/logout/*' => Http::response([], 200),
            '*/instance/connect/*' => Http::response(['base64' => 'data:image/png;base64,abc'], 200),
        ]);

        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'desconectado',
        ]);

        $service = app(EvolutionService::class);
        $service->generarQr($user);
        $service->generarQr($user->fresh());

        $logouts = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/instance/logout/'))
            ->count();

        $this->assertSame(1, $logouts);
    }

    public function test_desloguea_de_nuevo_si_paso_mas_tiempo_que_la_ventana_de_refresh(): void
    {
        Http::fake([
            '*/instance/logout/*' => Http::response([], 200),
            '*/instance/connect/*' => Http::response(['base64' => 'data:image/png;base64,abc'], 200),
        ]);

        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'desconectado',
        ]);

        $service = app(EvolutionService::class);
        $service->generarQr($user);

        $this->travel(46)->seconds();

        $service->generarQr($user->fresh());

        $logouts = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/instance/logout/'))
            ->count();

        $this->assertSame(2, $logouts);
    }

    public function test_desloguea_de_nuevo_si_el_webhook_marca_desconexion_a_mitad_de_intento(): void
    {
        config(['services.evolution.webhook_secret' => 'secreto-de-test']);

        Http::fake([
            '*/instance/logout/*' => Http::response([], 200),
            '*/instance/connect/*' => Http::response(['base64' => 'data:image/png;base64,abc'], 200),
        ]);

        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'desconectado',
        ]);

        app(EvolutionService::class)->generarQr($user);

        // Falla real de Baileys a mitad del intento (ej. 515 tras escanear)
        $this->postJson('/api/webhooks/evolution/secreto-de-test', [
            'event' => 'connection.update',
            'instance' => 'user_1',
            'data' => ['state' => 'connecting', 'statusReason' => 515],
        ])->assertOk();

        app(EvolutionService::class)->generarQr($user->fresh());

        $logouts = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/instance/logout/'))
            ->count();

        $this->assertSame(2, $logouts);
    }
}

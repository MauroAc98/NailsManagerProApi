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
}

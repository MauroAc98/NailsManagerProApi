<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EvolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvolutionServiceDesconectarTest extends TestCase
{
    use RefreshDatabase;

    public function test_marca_desconectado_cuando_el_logout_es_exitoso(): void
    {
        Http::fake([
            '*/instance/logout/*' => Http::response([], 200),
        ]);

        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        $resultado = app(EvolutionService::class)->desconectar($user);

        $this->assertTrue($resultado);
        $this->assertSame('desconectado', $user->fresh()->whatsapp_estado);
    }

    public function test_no_marca_desconectado_cuando_el_logout_falla(): void
    {
        Http::fake([
            '*/instance/logout/*' => Http::response([], 500),
        ]);

        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        $resultado = app(EvolutionService::class)->desconectar($user);

        $this->assertFalse($resultado);
        $this->assertSame('conectado', $user->fresh()->whatsapp_estado);
    }
}

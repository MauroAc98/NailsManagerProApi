<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EvolutionWebhookMessagesUpsertTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-de-test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.evolution.webhook_secret' => self::SECRETO]);
    }

    private function postMessagesUpsert(string $instanceName, string $remoteJid, string $texto, bool $fromMe = false): TestResponse
    {
        return $this->postJson('/api/webhooks/evolution/'.self::SECRETO, [
            'event' => 'messages.upsert',
            'instance' => $instanceName,
            'data' => [
                'key' => [
                    'remoteJid' => $remoteJid,
                    'fromMe' => $fromMe,
                ],
                'message' => [
                    'conversation' => $texto,
                ],
            ],
        ]);
    }

    public function test_marca_opt_out_y_confirma_cuando_responde_baja(): void
    {
        Http::fake(['*/message/sendText/*' => Http::response(['key' => ['id' => 'msg-1']], 200)]);

        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Sofía',
            'apellido' => 'Gómez',
            'telefono' => '3765252395',
        ]);

        $this->postMessagesUpsert('user_1', '5493765252395@s.whatsapp.net', 'BAJA')
            ->assertOk();

        $this->assertTrue($cliente->fresh()->whatsapp_opt_out);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/message/sendText/'));
    }

    public function test_ignora_mensajes_propios_del_salon(): void
    {
        Http::fake(['*/message/sendText/*' => Http::response(['key' => ['id' => 'msg-1']], 200)]);

        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Sofía',
            'telefono' => '3765252395',
        ]);

        $this->postMessagesUpsert('user_1', '5493765252395@s.whatsapp.net', 'BAJA', fromMe: true)
            ->assertOk();

        $this->assertFalse($cliente->fresh()->whatsapp_opt_out);
    }

    public function test_no_dispara_con_texto_que_solo_menciona_la_palabra(): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Sofía',
            'telefono' => '3765252395',
        ]);

        $this->postMessagesUpsert('user_1', '5493765252395@s.whatsapp.net', 'quiero saber si hay alguna baja de precio')
            ->assertOk();

        $this->assertFalse($cliente->fresh()->whatsapp_opt_out);
    }

    public function test_matchea_telefono_ignorando_diferencias_de_formato(): void
    {
        Http::fake(['*/message/sendText/*' => Http::response(['key' => ['id' => 'msg-1']], 200)]);

        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        // Guardado sin el "9" que WhatsApp inserta para celulares argentinos
        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Sofía',
            'telefono' => '+54 376 525-2395',
        ]);

        $this->postMessagesUpsert('user_1', '5493765252395@s.whatsapp.net', 'baja')
            ->assertOk();

        $this->assertTrue($cliente->fresh()->whatsapp_opt_out);
    }

    public function test_marca_opt_out_en_todas_las_fichas_duplicadas_del_mismo_telefono(): void
    {
        Http::fake(['*/message/sendText/*' => Http::response(['key' => ['id' => 'msg-1']], 200)]);

        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        // Misma persona cargada dos veces con formato distinto — el "9" de
        // WhatsApp hace que ambas pasen la validación de único por string
        // exacto, pero comparten los mismos últimos 10 dígitos.
        $clienteA = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Sofía A',
            'telefono' => '+543765252395',
        ]);

        $clienteB = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Sofía B',
            'telefono' => '+5493765252395',
        ]);

        $this->postMessagesUpsert('user_1', '5493765252395@s.whatsapp.net', 'BAJA')
            ->assertOk();

        $this->assertTrue($clienteA->fresh()->whatsapp_opt_out);
        $this->assertTrue($clienteB->fresh()->whatsapp_opt_out);
    }

    public function test_ignora_mensajes_de_grupos(): void
    {
        Http::fake(['*/message/sendText/*' => Http::response(['key' => ['id' => 'msg-1']], 200)]);

        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Sofía',
            'telefono' => '3765252395',
        ]);

        // Mismos dígitos que el cliente, pero con sufijo de grupo — el guard
        // de @s.whatsapp.net tiene que cortarlo antes de llegar a matchear.
        $this->postMessagesUpsert('user_1', '5493765252395@g.us', 'BAJA')
            ->assertOk();

        $this->assertFalse($cliente->fresh()->whatsapp_opt_out);
    }
}

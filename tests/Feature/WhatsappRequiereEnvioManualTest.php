<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappEstadoHistorial;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappRequiereEnvioManualTest extends TestCase
{
    use RefreshDatabase;

    public function test_true_sin_instancia_de_whatsapp_vinculada(): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => null,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => true]);
    }

    public function test_false_sin_historial_malo_ni_mensajes_fallidos(): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => false]);
    }

    public function test_true_con_desconexion_terminal_reciente(): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        WhatsappEstadoHistorial::create([
            'user_id' => $user->id,
            'estado' => 'desconectado',
            'status_reason' => 401,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => true]);
    }

    public function test_false_con_desconexion_terminal_pero_reconexion_posterior(): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        $desconexion = WhatsappEstadoHistorial::create([
            'user_id' => $user->id,
            'estado' => 'desconectado',
            'status_reason' => 401,
        ]);
        $desconexion->created_at = now()->subDays(5);
        $desconexion->save();

        $reconexion = WhatsappEstadoHistorial::create([
            'user_id' => $user->id,
            'estado' => 'conectado',
        ]);
        $reconexion->created_at = now()->subDays(4);
        $reconexion->save();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => false]);
    }

    public function test_false_con_desconexion_terminal_fuera_de_ventana(): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        $historial = WhatsappEstadoHistorial::create([
            'user_id' => $user->id,
            'estado' => 'desconectado',
            'status_reason' => 401,
        ]);
        $historial->created_at = now()->subDays(31);
        $historial->save();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => false]);
    }

    public function test_true_con_ratio_de_fallos_alto_y_muestra_suficiente(): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        for ($i = 0; $i < 5; $i++) {
            WhatsappMensaje::create([
                'user_id' => $user->id,
                'numero' => '5491100000000',
                'mensaje' => 'test',
                'tipo' => 'confirmacion',
                'status' => $i < 3 ? 'failed' : 'delivered',
                'intentos' => 1,
                'created_at' => now()->subDays(1),
                'updated_at' => now()->subDays(1),
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => true]);
    }

    public function test_false_con_muestra_chica_aunque_todo_haya_fallado(): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        WhatsappMensaje::create([
            'user_id' => $user->id,
            'numero' => '5491100000000',
            'mensaje' => 'test',
            'tipo' => 'confirmacion',
            'status' => 'failed',
            'intentos' => 1,
            'created_at' => now()->subDays(1),
            'updated_at' => now()->subDays(1),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => false]);
    }

    public function test_false_con_solo_mensajes_manuales_sin_fallos_automaticos(): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        for ($i = 0; $i < 8; $i++) {
            WhatsappMensaje::create([
                'user_id' => $user->id,
                'numero' => '5491100000000',
                'mensaje' => '',
                'tipo' => 'recordatorio',
                'status' => 'manual',
                'intentos' => 1,
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => false]);
    }

    // Los envíos manuales no dicen nada sobre si el envío AUTOMÁTICO
    // funciona: mezclarlos en el denominador diluye el ratio real y puede
    // "curar" el flag antes de tiempo. Acá el ratio automático real es
    // 1/5 = 20% (por encima del umbral, con muestra suficiente), pero
    // mezclado con 60 mensajes manuales queda en 1/65 ≈ 1.5% — sin el fix
    // el flag daría false pese a que el WhatsApp automático sigue fallando.
    public function test_true_con_ratio_automatico_real_por_encima_del_umbral_pese_a_diluirse_con_manuales(): void
    {
        $user = User::factory()->create([
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        for ($i = 0; $i < 5; $i++) {
            WhatsappMensaje::create([
                'user_id' => $user->id,
                'numero' => '5491100000000',
                'mensaje' => 'test',
                'tipo' => 'confirmacion',
                'status' => $i === 0 ? 'failed' : 'delivered',
                'intentos' => 1,
            ]);
        }

        for ($i = 0; $i < 60; $i++) {
            WhatsappMensaje::create([
                'user_id' => $user->id,
                'numero' => '5491100000000',
                'mensaje' => '',
                'tipo' => 'recordatorio',
                'status' => 'manual',
                'intentos' => 1,
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => true]);
    }
}

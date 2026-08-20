<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappRequiereEnvioManualTest extends TestCase
{
    use RefreshDatabase;

    public function test_true_sin_telefono_cargado(): void
    {
        $user = User::factory()->create(['telefono' => null]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => true]);
    }

    public function test_true_con_locale_pt_br(): void
    {
        $user = User::factory()->create(['telefono' => '3765000000', 'locale' => 'pt-BR']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => true]);
    }

    public function test_false_con_telefono_y_sin_mensajes_fallidos(): void
    {
        $user = User::factory()->create(['telefono' => '3765000000', 'locale' => 'es']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => false]);
    }

    public function test_true_con_ratio_de_fallos_alto_y_muestra_suficiente(): void
    {
        $user = User::factory()->create(['telefono' => '3765000000']);

        for ($i = 0; $i < 5; $i++) {
            WhatsappMensaje::create([
                'user_id' => $user->id,
                'numero' => '5491100000000',
                'provider' => 'cloud_api',
                'mensaje' => 'test',
                'tipo' => 'confirmacion',
                'status' => $i < 3 ? 'failed' : 'delivered',
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
        $user = User::factory()->create(['telefono' => '3765000000']);

        WhatsappMensaje::create([
            'user_id' => $user->id,
            'numero' => '5491100000000',
            'provider' => 'cloud_api',
            'mensaje' => 'test',
            'tipo' => 'confirmacion',
            'status' => 'failed',
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
        $user = User::factory()->create(['telefono' => '3765000000']);

        for ($i = 0; $i < 8; $i++) {
            WhatsappMensaje::create([
                'user_id' => $user->id,
                'numero' => '5491100000000',
                'provider' => 'cloud_api',
                'mensaje' => '',
                'tipo' => 'recordatorio',
                'status' => 'manual',
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
        $user = User::factory()->create(['telefono' => '3765000000']);

        for ($i = 0; $i < 5; $i++) {
            WhatsappMensaje::create([
                'user_id' => $user->id,
                'numero' => '5491100000000',
                'provider' => 'cloud_api',
                'mensaje' => 'test',
                'tipo' => 'confirmacion',
                'status' => $i === 0 ? 'failed' : 'delivered',
            ]);
        }

        for ($i = 0; $i < 60; $i++) {
            WhatsappMensaje::create([
                'user_id' => $user->id,
                'numero' => '5491100000000',
                'provider' => 'cloud_api',
                'mensaje' => '',
                'tipo' => 'recordatorio',
                'status' => 'manual',
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => true]);
    }

    // Los fallos históricos de Evolution (provider='evolution') no deben
    // contarse en el ratio de Cloud API — sin este filtro, el cutover
    // reciente arrastraría fallos de un proveedor que ya no existe.
    public function test_fallos_de_evolution_no_cuentan_para_el_ratio_de_cloud_api(): void
    {
        $user = User::factory()->create(['telefono' => '3765000000']);

        for ($i = 0; $i < 5; $i++) {
            WhatsappMensaje::create([
                'user_id' => $user->id,
                'numero' => '5491100000000',
                'provider' => 'evolution',
                'mensaje' => 'test',
                'tipo' => 'confirmacion',
                'status' => 'failed',
                'created_at' => now()->subDays(1),
                'updated_at' => now()->subDays(1),
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => false]);
    }
}

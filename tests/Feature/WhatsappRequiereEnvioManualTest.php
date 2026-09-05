<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappConnection;
use App\Services\CloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappRequiereEnvioManualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(CloudApiService::CACHE_KEY_SALUD);
    }

    public function test_true_sin_telefono_cargado(): void
    {
        $user = User::factory()->create([
            'telefono' => null,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => true]);
    }

    public function test_true_sin_direccion_cargada(): void
    {
        $user = User::factory()->create([
            'telefono' => '3765000000',
            'direccion' => null,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => true]);
    }

    public function test_true_con_direccion_solo_espacios_en_blanco(): void
    {
        $user = User::factory()->create([
            'telefono' => '3765000000',
            'direccion' => '   ',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => true]);
    }

    public function test_true_con_telefono_solo_espacios_en_blanco(): void
    {
        $user = User::factory()->create([
            'telefono' => '   ',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => true]);
    }

    public function test_true_con_locale_pt_br(): void
    {
        $user = User::factory()->create([
            'telefono' => '3765000000',
            'locale' => 'pt-BR',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => true]);
    }

    public function test_true_con_veredicto_de_salud_cacheado_en_rojo(): void
    {
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'RED']);

        $user = User::factory()->create([
            'telefono' => '3765000000',
            'locale' => 'es',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => true]);
    }

    public function test_false_con_todas_las_condiciones_en_orden_y_cache_verde(): void
    {
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'GREEN']);

        $user = User::factory()->create([
            'telefono' => '3765000000',
            'locale' => 'es',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => false]);
    }

    public function test_false_con_todas_las_condiciones_en_orden_y_cache_amarillo(): void
    {
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'YELLOW']);

        $user = User::factory()->create([
            'telefono' => '3765000000',
            'locale' => 'es',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => false]);
    }

    public function test_false_con_todas_las_condiciones_en_orden_y_cache_vacio(): void
    {
        $user = User::factory()->create([
            'telefono' => '3765000000',
            'locale' => 'es',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => false]);
    }

    // §Design "Gate invariant": expirada es un criterio más de
    // criterioRequiereEnvioManualWhatsapp, con todo lo demás sano.
    public function test_true_con_conexion_propia_expirada_y_todo_lo_demas_sano(): void
    {
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'GREEN']);

        $user = User::factory()->create([
            'telefono' => '3765000000',
            'direccion' => 'Calle Falsa 123',
            'locale' => 'es',
        ]);

        WhatsappConnection::create([
            'user_id' => $user->id,
            'waba_id' => '111111111111111',
            'phone_number_id' => '222222222222222',
            'display_phone_number' => '5491122334455',
            'verified_name' => 'Negocio Demo',
            'access_token' => 'EAAG-secreto-de-tenant',
            'token_expires_at' => now()->subMinute()->timestamp,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['whatsapp_requiere_envio_manual' => true]);
    }

    public function test_no_hace_llamadas_http_al_evaluar_el_criterio_en_un_request(): void
    {
        Http::fake();

        $user = User::factory()->create([
            'telefono' => '3765000000',
            'locale' => 'es',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk();

        Http::assertNothingSent();
    }
}

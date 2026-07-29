<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappTemplateDefaultLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_plantilla_default_se_siembra_en_portugues_para_locale_pt_br(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'locale' => 'pt-BR']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/whatsapp-templates')
            ->assertOk();

        $recordatorio = collect($response->json())->firstWhere('tipo', 'recordatorio');
        $confirmacion = collect($response->json())->firstWhere('tipo', 'confirmacion');

        $this->assertStringContainsString('Oi {nombre}', $recordatorio['contenido']);
        $this->assertStringContainsString('Oi {nombre}', $confirmacion['contenido']);
    }

    public function test_plantilla_default_se_siembra_en_español_para_locale_es(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'locale' => 'es']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/whatsapp-templates')
            ->assertOk();

        $recordatorio = collect($response->json())->firstWhere('tipo', 'recordatorio');

        $this->assertStringContainsString('Hola {nombre}', $recordatorio['contenido']);
    }

    public function test_plantilla_default_cae_a_español_cuando_no_hay_locale(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'locale' => null]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/whatsapp-templates')
            ->assertOk();

        $recordatorio = collect($response->json())->firstWhere('tipo', 'recordatorio');

        $this->assertStringContainsString('Hola {nombre}', $recordatorio['contenido']);
    }

    public function test_palabra_clave_de_opt_out_BAJA_se_mantiene_igual_en_todos_los_locales(): void
    {
        // El webhook de Evolution (EvolutionWebhookController::MENSAJE_OPT_OUT)
        // solo reconoce el literal "BAJA" para dar de baja a una clienta — la
        // plantilla en portugués debe seguir instruyendo a responder "BAJA" tal
        // cual (no "SAIR" ni ninguna variante), porque traducir esa palabra
        // rompería la detección de opt-out sin tocar el webhook.
        $userEs = User::factory()->create(['is_exempt' => true, 'locale' => 'es']);
        $userPt = User::factory()->create(['is_exempt' => true, 'locale' => 'pt-BR']);

        $recordatorioEs = collect(
            $this->actingAs($userEs, 'sanctum')->getJson('/api/whatsapp-templates')->json()
        )->firstWhere('tipo', 'recordatorio');

        $recordatorioPt = collect(
            $this->actingAs($userPt, 'sanctum')->getJson('/api/whatsapp-templates')->json()
        )->firstWhere('tipo', 'recordatorio');

        $this->assertStringContainsString('BAJA', $recordatorioEs['contenido']);
        $this->assertStringContainsString('BAJA', $recordatorioPt['contenido']);
    }
}

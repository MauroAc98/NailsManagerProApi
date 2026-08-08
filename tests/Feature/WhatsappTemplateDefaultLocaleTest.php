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

    public function test_plantilla_default_de_recordatorio_ya_no_menciona_opt_out_por_baja(): void
    {
        // La mención a "respondé BAJA" se sacó de la plantilla default: en
        // toda la base de producción, ninguna clienta la usó nunca. El
        // webhook (EvolutionWebhookController::MENSAJE_OPT_OUT) se deja
        // intacto por si alguien la escribe espontáneamente, pero ya no se
        // la sugiere en el mensaje.
        $userEs = User::factory()->create(['is_exempt' => true, 'locale' => 'es']);
        $userPt = User::factory()->create(['is_exempt' => true, 'locale' => 'pt-BR']);

        $recordatorioEs = collect(
            $this->actingAs($userEs, 'sanctum')->getJson('/api/whatsapp-templates')->json()
        )->firstWhere('tipo', 'recordatorio');

        $recordatorioPt = collect(
            $this->actingAs($userPt, 'sanctum')->getJson('/api/whatsapp-templates')->json()
        )->firstWhere('tipo', 'recordatorio');

        $this->assertStringNotContainsString('BAJA', $recordatorioEs['contenido']);
        $this->assertStringNotContainsString('BAJA', $recordatorioPt['contenido']);
    }
}

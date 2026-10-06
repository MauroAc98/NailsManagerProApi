<?php

namespace Tests\Feature;

use App\Services\Reservas\MercadoPagoService;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

/**
 * GET /api/public/{slug}/terminos — antes de esto, el frontend mostraba
 * "Cancelación gratis hasta 24 h antes" con un valor mockeado en el cliente,
 * el mismo para cualquier negocio real: una promesa sin nada real detras.
 */
class PublicTerminosTest extends TestCase
{
    use CreaSalonPublico, RefreshDatabase;

    // deposito = la seña fija exacta (sin gross-up por comision ni retencion):
    // es lo que el cliente ve y despues paga en el checkout de MP.
    public function test_devuelve_el_deposito_fijo_exacto(): void
    {
        config(['reservas.pago_minutos' => 15, 'reservas.anticipacion_minutos' => 120, 'reservas.cancelacion_horas' => 24]);
        $user = $this->crearSalon(['sena_monto' => 5000]);

        $this->getJson("/api/public/{$user->slug}/terminos")
            ->assertOk()
            ->assertExactJson([
                'deposito' => 5000.0,
                'ventana_pago_minutos' => 15,
                'anticipacion_minutos' => 120,
                'ventana_cancelacion_horas' => 24,
            ]);
    }

    public function test_la_retencion_iibb_no_cambia_el_deposito(): void
    {
        $user = $this->crearSalon(['sena_monto' => 5000, 'retencion_iibb_porcentaje' => 4]);

        $this->getJson("/api/public/{$user->slug}/terminos")
            ->assertOk()
            ->assertJsonPath('deposito', 5000);
    }

    public function test_la_comision_global_no_cambia_el_deposito(): void
    {
        Setting::create(['key' => 'comision_mp_porcentaje', 'value' => '15']);
        $user = $this->crearSalon(['sena_monto' => 5000]);

        $this->getJson("/api/public/{$user->slug}/terminos")
            ->assertOk()
            ->assertJsonPath('deposito', 5000);
    }

    public function test_en_porcentaje_el_deposito_es_null_y_expone_tipo_y_porcentaje(): void
    {
        $user = $this->crearSalon(['sena_tipo' => 'porcentaje', 'sena_porcentaje' => 30, 'sena_monto' => null]);

        $this->getJson("/api/public/{$user->slug}/terminos")
            ->assertOk()
            ->assertJsonPath('deposito', null)
            ->assertJsonPath('sena_tipo', 'porcentaje')
            ->assertJsonPath('sena_porcentaje', 30);
    }

    public function test_sin_sena_configurada_el_deposito_es_cero(): void
    {
        $user = $this->crearSalon(['sena_monto' => null]);

        $this->getJson("/api/public/{$user->slug}/terminos")
            ->assertOk()
            ->assertJsonPath('deposito', 0);
    }

    public function test_refleja_un_valor_de_config_distinto_al_default(): void
    {
        config(['reservas.cancelacion_horas' => 48]);
        $user = $this->crearSalon();

        $this->getJson("/api/public/{$user->slug}/terminos")
            ->assertOk()
            ->assertJsonPath('ventana_cancelacion_horas', 48);
    }

    public function test_404_para_slug_inexistente(): void
    {
        $this->getJson('/api/public/no-existe/terminos')->assertNotFound();
    }

    public function test_404_si_la_suscripcion_esta_vencida(): void
    {
        $user = $this->crearSalon(['is_exempt' => false]);
        $this->crearSuscripcion($user, 'VENCIDO', now()->subDay());

        $this->getJson("/api/public/{$user->slug}/terminos")->assertNotFound();
    }
}

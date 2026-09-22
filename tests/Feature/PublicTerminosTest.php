<?php

namespace Tests\Feature;

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

    public function test_devuelve_el_deposito_del_negocio_y_los_valores_de_config(): void
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

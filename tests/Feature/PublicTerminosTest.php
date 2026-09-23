<?php

namespace Tests\Feature;

use App\Services\Reservas\MercadoPagoService;
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

    // deposito ya NO es el neto de sena_monto: es lo que se le cobra a la
    // clienta para que, descontada la comision de MP, el negocio reciba los
    // 5000 completos (ver MercadoPagoService::montoACobrar) — tiene que
    // coincidir con lo que despues ve en el checkout real de MP.
    public function test_devuelve_el_deposito_a_cobrar_incluyendo_la_comision_de_mp(): void
    {
        config(['reservas.pago_minutos' => 15, 'reservas.anticipacion_minutos' => 120, 'reservas.cancelacion_horas' => 24]);
        $user = $this->crearSalon(['sena_monto' => 5000]);
        $depositoEsperado = round(5000 / (1 - MercadoPagoService::COMISION_MP_DEFAULT / 100), 2);

        $this->getJson("/api/public/{$user->slug}/terminos")
            ->assertOk()
            ->assertExactJson([
                'deposito' => $depositoEsperado,
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

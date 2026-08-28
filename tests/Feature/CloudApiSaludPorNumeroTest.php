<?php

namespace Tests\Feature;

use App\Services\CloudApiService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * §4 del diseño: la clave de salud se namespacea por phone_number_id de tenant,
 * pero el número compartido (y todo caller sin argumento) sigue en la clave
 * legacy CACHE_KEY_SALUD — backward-compatible por construcción.
 */
class CloudApiSaludPorNumeroTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_clave_salud_sin_argumento_es_la_clave_legacy(): void
    {
        $this->assertSame(CloudApiService::CACHE_KEY_SALUD, CloudApiService::claveSalud());
        $this->assertSame(CloudApiService::CACHE_KEY_SALUD, CloudApiService::claveSalud(null));
        $this->assertSame(CloudApiService::CACHE_KEY_SALUD, CloudApiService::claveSalud(''));
    }

    public function test_clave_salud_del_numero_compartido_es_la_clave_legacy(): void
    {
        config(['services.whatsapp_cloud.phone_number_id' => '15550001111']);

        $this->assertSame(CloudApiService::CACHE_KEY_SALUD, CloudApiService::claveSalud('15550001111'));
    }

    public function test_clave_salud_de_un_numero_de_tenant_se_namespacea(): void
    {
        config(['services.whatsapp_cloud.phone_number_id' => '15550001111']);

        $this->assertSame(
            CloudApiService::CACHE_KEY_SALUD.':777888999',
            CloudApiService::claveSalud('777888999'),
        );
    }

    public function test_registrar_calidad_de_un_tenant_no_toca_el_veredicto_compartido(): void
    {
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'GREEN', 'origen' => 'seed']);

        $registro = app(CloudApiService::class)->registrarCalidad(
            ['event' => 'FLAGGED', 'display_phone_number' => '777888999', 'current_limit' => 'TIER_250'],
            '777888999',
        );

        $this->assertSame('RED', $registro['quality_rating']);
        $this->assertSame('RED', Cache::get(CloudApiService::CACHE_KEY_SALUD.':777888999')['quality_rating']);
        $this->assertSame(['quality_rating' => 'GREEN', 'origen' => 'seed'], Cache::get(CloudApiService::CACHE_KEY_SALUD));
    }

    public function test_esta_saludable_de_un_tenant_lee_su_propia_clave(): void
    {
        config(['services.whatsapp_cloud.calidad_bloqueante' => ['RED']]);
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'RED']);
        Cache::forever(CloudApiService::CACHE_KEY_SALUD.':777888999', ['quality_rating' => 'GREEN']);

        $service = app(CloudApiService::class);

        $this->assertTrue($service->estaSaludable('777888999'));
        $this->assertFalse($service->estaSaludable());
    }

    public function test_esta_saludable_de_un_tenant_hace_fail_open_cuando_no_hay_nada_cacheado(): void
    {
        config(['services.whatsapp_cloud.calidad_bloqueante' => ['RED']]);
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'RED']);

        $this->assertTrue(app(CloudApiService::class)->estaSaludable('numero-nunca-sembrado'));
    }
}

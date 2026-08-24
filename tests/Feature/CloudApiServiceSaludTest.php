<?php

namespace Tests\Feature;

use App\Services\CloudApiService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CloudApiServiceSaludTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(CloudApiService::CACHE_KEY_SALUD);
    }

    public function test_es_saludable_por_defecto_cuando_no_hay_nada_cacheado(): void
    {
        $this->assertTrue(app(CloudApiService::class)->estaSaludable());
    }

    public function test_no_es_saludable_cuando_el_rating_cacheado_esta_en_calidad_bloqueante(): void
    {
        config(['services.whatsapp_cloud.calidad_bloqueante' => ['RED']]);
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'RED']);

        $this->assertFalse(app(CloudApiService::class)->estaSaludable());
    }

    public function test_es_saludable_cuando_el_rating_cacheado_no_esta_en_calidad_bloqueante(): void
    {
        config(['services.whatsapp_cloud.calidad_bloqueante' => ['RED']]);
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'YELLOW']);

        $this->assertTrue(app(CloudApiService::class)->estaSaludable());
    }

    public function test_calidad_bloqueante_es_configurable_en_runtime(): void
    {
        config(['services.whatsapp_cloud.calidad_bloqueante' => ['RED', 'YELLOW']]);
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'YELLOW']);

        $this->assertFalse(app(CloudApiService::class)->estaSaludable());
    }
}

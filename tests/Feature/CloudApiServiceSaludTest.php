<?php

namespace Tests\Feature;

use App\Services\CloudApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function test_no_hace_llamadas_http_cuando_hay_un_veredicto_cacheado(): void
    {
        Http::fake();
        config(['services.whatsapp_cloud.calidad_bloqueante' => ['RED']]);
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'RED']);

        app(CloudApiService::class)->estaSaludable();

        Http::assertNothingSent();
    }

    public function test_no_hace_llamadas_http_cuando_el_cache_esta_vacio_fail_open(): void
    {
        Http::fake();

        app(CloudApiService::class)->estaSaludable();

        Http::assertNothingSent();
    }

    public function test_es_saludable_fail_open_cuando_la_lectura_de_cache_lanza_excepcion(): void
    {
        Cache::shouldReceive('get')
            ->once()
            ->with(CloudApiService::CACHE_KEY_SALUD)
            ->andThrow(new \RuntimeException('DB pool exhausted'));

        Log::shouldReceive('error')->once();

        $this->assertTrue(app(CloudApiService::class)->estaSaludable());
    }
}

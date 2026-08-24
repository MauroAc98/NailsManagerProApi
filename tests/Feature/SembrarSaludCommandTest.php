<?php

namespace Tests\Feature;

use App\Services\CloudApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SembrarSaludCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp_cloud.token' => 'fake-token-123',
            'services.whatsapp_cloud.phone_number_id' => '1315423274987306',
            'services.whatsapp_cloud.api_version' => 'v26.0',
        ]);

        Cache::forget(CloudApiService::CACHE_KEY_SALUD);
    }

    public function test_puebla_un_cache_vacio_con_un_quality_rating_valido(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['quality_rating' => 'GREEN'], 200),
        ]);

        $this->artisan('whatsapp:sembrar-salud')->assertExitCode(0);

        $cache = Cache::get(CloudApiService::CACHE_KEY_SALUD);

        $this->assertSame('GREEN', $cache['quality_rating']);
        $this->assertSame('seed', $cache['origen']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'v26.0/1315423274987306')
                && ($request['fields'] ?? null) === 'quality_rating'
                && $request->hasHeader('Authorization', 'Bearer fake-token-123');
        });
    }

    public function test_error_4xx_no_escribe_y_preserva_el_valor_previo(): void
    {
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'RED', 'origen' => 'webhook']);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'forbidden']], 403),
        ]);

        $this->artisan('whatsapp:sembrar-salud')->assertExitCode(0);

        $this->assertSame(['quality_rating' => 'RED', 'origen' => 'webhook'], Cache::get(CloudApiService::CACHE_KEY_SALUD));
    }

    public function test_error_5xx_no_escribe_y_preserva_el_valor_previo(): void
    {
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'GREEN', 'origen' => 'seed']);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'server error']], 500),
        ]);

        $this->artisan('whatsapp:sembrar-salud')->assertExitCode(0);

        $this->assertSame(['quality_rating' => 'GREEN', 'origen' => 'seed'], Cache::get(CloudApiService::CACHE_KEY_SALUD));
    }

    public function test_timeout_no_escribe_y_preserva_el_valor_previo(): void
    {
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'YELLOW', 'origen' => 'webhook']);

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('timed out');
        });

        $this->artisan('whatsapp:sembrar-salud')->assertExitCode(0);

        $this->assertSame(['quality_rating' => 'YELLOW', 'origen' => 'webhook'], Cache::get(CloudApiService::CACHE_KEY_SALUD));
    }
}

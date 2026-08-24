<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Services\CloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CloudApiWebhookCalidadTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'app-secret-de-test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp_cloud.app_secret' => self::APP_SECRET]);
    }

    private function postSigned(array $payload, ?string $secret = null): TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret ?? self::APP_SECRET);

        return $this->call('POST', '/api/webhooks/whatsapp-cloud', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /**
     * Payload base tomado de la muestra confirmada en Meta App Dashboard
     * (webhook field config UI, phone_number_quality_update, API v26.0).
     */
    private function qualityChangePayload($value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-id',
                'changes' => [[
                    'field' => 'phone_number_quality_update',
                    'value' => $value,
                ]],
            ]],
        ];
    }

    private function eventValue(string $event, array $extra = []): array
    {
        return array_merge([
            'display_phone_number' => '16505551111',
            'event' => $event,
            'current_limit' => 'TIER_250',
            'old_limit' => 'TIER_NOT_SET',
            'max_daily_conversations_per_business' => 'TIER_250',
        ], $extra);
    }

    public function test_flagged_cachea_un_veredicto_rojo(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($mensaje, $contexto) => is_string($mensaje)
                && ($contexto['event'] ?? null) === 'FLAGGED'
                && ($contexto['quality_rating'] ?? null) === 'RED');

        $this->postSigned($this->qualityChangePayload($this->eventValue('FLAGGED')))->assertOk();

        $cache = Cache::get(CloudApiService::CACHE_KEY_SALUD);

        $this->assertSame('RED', $cache['quality_rating']);
        $this->assertSame('FLAGGED', $cache['event']);
    }

    public function test_unflagged_cachea_un_veredicto_verde(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($mensaje, $contexto) => is_string($mensaje)
                && ($contexto['event'] ?? null) === 'UNFLAGGED'
                && ($contexto['quality_rating'] ?? null) === 'GREEN');

        $this->postSigned($this->qualityChangePayload($this->eventValue('UNFLAGGED')))->assertOk();

        $cache = Cache::get(CloudApiService::CACHE_KEY_SALUD);

        $this->assertSame('GREEN', $cache['quality_rating']);
        $this->assertSame('UNFLAGGED', $cache['event']);
    }

    #[DataProvider('eventosDeTierProvider')]
    public function test_eventos_de_tier_no_modifican_el_cache_y_loguean_info(string $evento): void
    {
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'RED', 'origen' => 'seed']);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($mensaje, $contexto) => is_string($mensaje) && ($contexto['event'] ?? null) === $evento);

        $this->postSigned($this->qualityChangePayload($this->eventValue($evento)))->assertOk();

        $cache = Cache::get(CloudApiService::CACHE_KEY_SALUD);

        $this->assertSame(['quality_rating' => 'RED', 'origen' => 'seed'], $cache);
    }

    public static function eventosDeTierProvider(): array
    {
        return [
            'ONBOARDING' => ['ONBOARDING'],
            'UPGRADE' => ['UPGRADE'],
            'DOWNGRADE' => ['DOWNGRADE'],
        ];
    }

    public function test_event_ausente_no_modifica_el_cache_y_loguea_warning(): void
    {
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'GREEN', 'origen' => 'webhook']);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($mensaje, $contexto) => $mensaje === 'whatsapp.calidad.evento_no_reconocido'
                && array_key_exists('event', $contexto)
                && $contexto['event'] === null);

        $this->postSigned($this->qualityChangePayload([
            'display_phone_number' => '16505551111',
        ]))->assertOk();

        $cache = Cache::get(CloudApiService::CACHE_KEY_SALUD);

        $this->assertSame(['quality_rating' => 'GREEN', 'origen' => 'webhook'], $cache);
    }

    public function test_event_no_reconocido_no_modifica_el_cache_y_loguea_warning(): void
    {
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'GREEN', 'origen' => 'webhook']);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($mensaje, $contexto) => $mensaje === 'whatsapp.calidad.evento_no_reconocido'
                && ($contexto['event'] ?? null) === 'ALGO_FUTURO_NO_DOCUMENTADO');

        $this->postSigned($this->qualityChangePayload($this->eventValue('ALGO_FUTURO_NO_DOCUMENTADO')))->assertOk();

        $cache = Cache::get(CloudApiService::CACHE_KEY_SALUD);

        $this->assertSame(['quality_rating' => 'GREEN', 'origen' => 'webhook'], $cache);
    }

    public function test_payload_con_statuses_y_cambio_de_calidad_procesa_ambos(): void
    {
        $user = User::factory()->create();
        $mensaje = WhatsappMensaje::create([
            'user_id' => $user->id,
            'numero' => '5493765123456',
            'mensaje' => 'texto',
            'tipo' => 'confirmacion',
            'message_id' => 'wamid.MIXED-CALIDAD',
            'status' => 'pending',
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-id',
                'changes' => [
                    [
                        'field' => 'messages',
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'metadata' => ['phone_number_id' => '123'],
                            'statuses' => [[
                                'id' => 'wamid.MIXED-CALIDAD',
                                'status' => 'delivered',
                                'timestamp' => (string) now()->timestamp,
                                'recipient_id' => '5493765123456',
                            ]],
                        ],
                    ],
                    [
                        'field' => 'phone_number_quality_update',
                        'value' => $this->eventValue('FLAGGED'),
                    ],
                ],
            ]],
        ];

        $this->postSigned($payload)->assertOk();

        $this->assertSame('delivered', $mensaje->fresh()->status);
        $this->assertSame('RED', Cache::get(CloudApiService::CACHE_KEY_SALUD)['quality_rating']);
    }

    public function test_value_malformado_no_revienta_y_devuelve_200(): void
    {
        $user = User::factory()->create();
        $mensaje = WhatsappMensaje::create([
            'user_id' => $user->id,
            'numero' => '5493765123456',
            'mensaje' => 'texto',
            'tipo' => 'confirmacion',
            'message_id' => 'wamid.MALFORMADO',
            'status' => 'pending',
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-id',
                'changes' => [
                    [
                        'field' => 'messages',
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'metadata' => ['phone_number_id' => '123'],
                            'statuses' => [[
                                'id' => 'wamid.MALFORMADO',
                                'status' => 'delivered',
                                'timestamp' => (string) now()->timestamp,
                                'recipient_id' => '5493765123456',
                            ]],
                        ],
                    ],
                    [
                        'field' => 'phone_number_quality_update',
                        'value' => 'esto-no-es-un-array',
                    ],
                ],
            ]],
        ];

        $this->postSigned($payload)->assertOk();

        $this->assertSame('delivered', $mensaje->fresh()->status);
    }
}

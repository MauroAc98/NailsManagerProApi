<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappConnection;
use App\Models\WhatsappMensaje;
use App\Services\CloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * §5 del diseño: ruteo por-tenant de phone_number_quality_update.
 * Rama 1 (0 filas) = escritura legacy incondicional, sin resolver, sin gating.
 * Rama 2 (>=1 fila) = escalera de resolución, luego clave por-tenant / legacy
 * compartida / drop con warning.
 */
class CloudApiWebhookRuteoTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'app-secret-de-test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp_cloud.app_secret' => self::APP_SECRET]);
        Cache::flush();
    }

    private function postSigned(array $payload): TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET);

        return $this->call('POST', '/api/webhooks/whatsapp-cloud', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function qualityPayload($value, string $entryId = 'waba-entry-id'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $entryId,
                'changes' => [[
                    'field' => 'phone_number_quality_update',
                    'value' => $value,
                ]],
            ]],
        ];
    }

    private function seedConexion(array $override = []): WhatsappConnection
    {
        return WhatsappConnection::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'waba_id' => 'WABA-DEFAULT',
            'phone_number_id' => 'PN-DEFAULT',
            'display_phone_number' => '5491199990000',
            'verified_name' => 'Salón Test',
            'access_token' => 'EAAG-token-de-tenant',
            'token_expires_at' => null,
        ], $override));
    }

    private function wabaConnQueries(): array
    {
        return array_values(array_filter(
            DB::connection()->getQueryLog(),
            fn ($q) => str_contains($q['query'], 'whatsapp_connections'),
        ));
    }

    // ── Rama 1 ──────────────────────────────────────────────────────────

    public function test_rama_1_sin_conexiones_escribe_la_clave_legacy_sin_invocar_el_resolver(): void
    {
        DB::connection()->enableQueryLog();

        $this->postSigned($this->qualityPayload([
            'display_phone_number' => '16505551111',
            'event' => 'FLAGGED',
            'current_limit' => 'TIER_250',
        ]))->assertOk();

        $this->assertSame('RED', Cache::get(CloudApiService::CACHE_KEY_SALUD)['quality_rating']);

        $queries = $this->wabaConnQueries();
        $this->assertCount(1, $queries, 'La rama 1 solo debe hacer el exists(), nunca el resolver');
        $this->assertStringContainsString('exists', $queries[0]['query']);
    }

    public function test_payload_solo_de_status_no_consulta_whatsapp_connections(): void
    {
        $this->seedConexion();

        $user = User::factory()->create();
        WhatsappMensaje::create([
            'user_id' => $user->id,
            'numero' => '5493765123456',
            'mensaje' => 'texto',
            'tipo' => 'confirmacion',
            'message_id' => 'wamid.SOLO-STATUS',
            'status' => 'pending',
        ]);

        DB::connection()->enableQueryLog();

        $this->postSigned([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-entry-id',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => '123'],
                        'statuses' => [[
                            'id' => 'wamid.SOLO-STATUS',
                            'status' => 'delivered',
                            'timestamp' => (string) now()->timestamp,
                        ]],
                    ],
                ]],
            ]],
        ])->assertOk();

        $this->assertCount(0, $this->wabaConnQueries());
    }

    // ── Rama 2 — escalera de resolución ─────────────────────────────────

    public function test_rama_2_resuelve_por_metadata_phone_number_id(): void
    {
        Log::spy();
        $conexion = $this->seedConexion(['phone_number_id' => 'PN-META', 'display_phone_number' => '5491111111111']);

        $this->postSigned($this->qualityPayload([
            'metadata' => ['phone_number_id' => 'PN-META'],
            'display_phone_number' => '5490000000000',
            'event' => 'FLAGGED',
            'current_limit' => 'TIER_250',
        ]))->assertOk();

        $this->assertSame('RED', Cache::get(CloudApiService::CACHE_KEY_SALUD.':PN-META')['quality_rating']);
        $this->assertNull(Cache::get(CloudApiService::CACHE_KEY_SALUD));
    }

    public function test_rama_2_resuelve_por_display_phone_number_normalizado_a_digitos(): void
    {
        $this->seedConexion([
            'phone_number_id' => 'PN-DISPLAY',
            'waba_id' => 'WABA-DISPLAY',
            'display_phone_number' => '16505551111',
        ]);

        $this->postSigned($this->qualityPayload(
            [
                'display_phone_number' => '+1 650-555-1111',
                'event' => 'UNFLAGGED',
                'current_limit' => 'TIER_1K',
            ],
            entryId: 'un-waba-que-no-matchea',
        ))->assertOk();

        $this->assertSame('GREEN', Cache::get(CloudApiService::CACHE_KEY_SALUD.':PN-DISPLAY')['quality_rating']);
        $this->assertNull(Cache::get(CloudApiService::CACHE_KEY_SALUD));
    }

    public function test_rama_2_resuelve_por_waba_id_como_ultimo_recurso(): void
    {
        $this->seedConexion([
            'phone_number_id' => 'PN-WABA',
            'waba_id' => 'WABA-ULTIMO-RECURSO',
            'display_phone_number' => '5491199990000',
        ]);

        $this->postSigned($this->qualityPayload(
            [
                'display_phone_number' => '16505551111',
                'event' => 'FLAGGED',
                'current_limit' => 'TIER_250',
            ],
            entryId: 'WABA-ULTIMO-RECURSO',
        ))->assertOk();

        $this->assertSame('RED', Cache::get(CloudApiService::CACHE_KEY_SALUD.':PN-WABA')['quality_rating']);
        $this->assertNull(Cache::get(CloudApiService::CACHE_KEY_SALUD));
    }

    public function test_rama_2_waba_id_ambiguo_no_resuelve_y_no_escribe_nada(): void
    {
        Log::spy();
        $this->seedConexion(['phone_number_id' => 'PN-A', 'waba_id' => 'WABA-COMPARTIDA', 'display_phone_number' => '5491100000001']);
        $this->seedConexion(['phone_number_id' => 'PN-B', 'waba_id' => 'WABA-COMPARTIDA', 'display_phone_number' => '5491100000002']);

        $this->postSigned($this->qualityPayload(
            [
                'display_phone_number' => '16505551111',
                'event' => 'FLAGGED',
                'current_limit' => 'TIER_250',
            ],
            entryId: 'WABA-COMPARTIDA',
        ))->assertOk();

        $this->assertNull(Cache::get(CloudApiService::CACHE_KEY_SALUD));
        $this->assertNull(Cache::get(CloudApiService::CACHE_KEY_SALUD.':PN-A'));
        $this->assertNull(Cache::get(CloudApiService::CACHE_KEY_SALUD.':PN-B'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($mensaje) => $mensaje === 'whatsapp.calidad.evento_sin_ruta');
    }

    public function test_rama_2_evento_sin_ruta_no_escribe_nada_y_loguea_warning(): void
    {
        Log::spy();
        $this->seedConexion(['phone_number_id' => 'PN-OTRO', 'waba_id' => 'WABA-OTRA', 'display_phone_number' => '5491133334444']);

        $this->postSigned($this->qualityPayload(
            [
                'metadata' => ['phone_number_id' => 'PN-DESCONOCIDO'],
                'display_phone_number' => '19999999999',
                'event' => 'FLAGGED',
                'current_limit' => 'TIER_250',
            ],
            entryId: 'WABA-DESCONOCIDA',
        ))->assertOk();

        $this->assertNull(Cache::get(CloudApiService::CACHE_KEY_SALUD));
        $this->assertNull(Cache::get(CloudApiService::CACHE_KEY_SALUD.':PN-OTRO'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($mensaje, $contexto) => $mensaje === 'whatsapp.calidad.evento_sin_ruta'
                && ($contexto['entry_id'] ?? null) === 'WABA-DESCONOCIDA');
    }

    public function test_rama_2_evento_del_numero_compartido_escribe_la_clave_legacy(): void
    {
        config(['services.whatsapp_cloud.phone_number_id' => '15550009999']);
        $this->seedConexion(['phone_number_id' => 'PN-TENANT', 'waba_id' => 'WABA-TENANT', 'display_phone_number' => '5491155556666']);

        $this->postSigned($this->qualityPayload(
            [
                'display_phone_number' => '+1 555-000-9999',
                'event' => 'FLAGGED',
                'current_limit' => 'TIER_250',
            ],
            entryId: 'WABA-DESCONOCIDA',
        ))->assertOk();

        $this->assertSame('RED', Cache::get(CloudApiService::CACHE_KEY_SALUD)['quality_rating']);
        $this->assertNull(Cache::get(CloudApiService::CACHE_KEY_SALUD.':PN-TENANT'));
    }

    // ── procesarStatus queda FUERA del try/catch ───────────────────────

    public function test_una_falla_en_procesar_status_propaga_500(): void
    {
        $user = User::factory()->create();
        WhatsappMensaje::create([
            'user_id' => $user->id,
            'numero' => '5493765123456',
            'mensaje' => 'texto',
            'tipo' => 'confirmacion',
            'message_id' => 'wamid.BOOM',
            'status' => 'pending',
        ]);

        WhatsappMensaje::retrieved(function () {
            throw new \RuntimeException('db boom en procesarStatus');
        });

        $this->postSigned([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-entry-id',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'statuses' => [[
                            'id' => 'wamid.BOOM',
                            'status' => 'delivered',
                            'timestamp' => (string) now()->timestamp,
                        ]],
                    ],
                ]],
            ]],
        ])->assertStatus(500);
    }
}

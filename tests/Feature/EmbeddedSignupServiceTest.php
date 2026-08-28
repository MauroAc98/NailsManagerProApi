<?php

namespace Tests\Feature;

use App\Exceptions\PhoneNumberYaVinculadoException;
use App\Models\User;
use App\Models\WhatsappConnection;
use App\Services\EmbeddedSignupService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * EmbeddedSignupService::conectar() — secuencia de 3 llamadas de Meta
 * (design §3). Contrato de transacción: las llamadas HTTP corren FUERA de
 * toda transacción; el upsert es el único paso transaccional y va último.
 * Orden deliberado: subscribe (3) ANTES de persistir (4).
 */
class EmbeddedSignupServiceTest extends TestCase
{
    use RefreshDatabase;

    private const WABA_ID = '111111111111111';

    private const PHONE_NUMBER_ID = '222222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp_es.enabled' => true,
            'services.whatsapp_es.allow_all' => true,
            'services.whatsapp_es.allowed_user_ids' => [],
            'services.whatsapp_es.graph_version' => 'v26.0',
            'services.whatsapp_es.app_id' => 'app-id-123',
            'services.whatsapp_es.app_secret' => 'app-secret-xyz',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides  claves: token, phone_numbers, subscribed
     */
    private function fakeMeta(array $overrides = []): void
    {
        Http::fake(array_merge([
            'graph.facebook.com/*/oauth/access_token*' => Http::response([
                'access_token' => 'EAAG-token-de-tenant',
                'token_type' => 'bearer',
                'expires_in' => 0,
            ]),
            'graph.facebook.com/*/phone_numbers*' => Http::response([
                'data' => [[
                    'id' => self::PHONE_NUMBER_ID,
                    'display_phone_number' => '+54 9 11 2233-4455',
                    'verified_name' => 'Salón Demo',
                ]],
            ]),
            'graph.facebook.com/*/subscribed_apps' => Http::response(['success' => true]),
        ], $overrides));
    }

    private function conectar(?User $user = null, ?string $phoneNumberId = self::PHONE_NUMBER_ID): WhatsappConnection
    {
        return app(EmbeddedSignupService::class)->conectar(
            $user ?? User::factory()->create(),
            'es-code-fresco',
            self::WABA_ID,
            $phoneNumberId,
        );
    }

    public function test_happy_path_persiste_la_conexion_y_suscribe_antes_de_escribir(): void
    {
        $this->freezeTime();
        $this->fakeMeta();
        $user = User::factory()->create();

        $conexion = $this->conectar($user);

        $this->assertDatabaseCount('whatsapp_connections', 1);
        $this->assertSame($user->id, $conexion->user_id);
        $this->assertSame(self::WABA_ID, $conexion->waba_id);
        $this->assertSame(self::PHONE_NUMBER_ID, $conexion->phone_number_id);
        $this->assertSame('5491122334455', $conexion->display_phone_number);
        $this->assertSame('Salón Demo', $conexion->verified_name);
        $this->assertSame('EAAG-token-de-tenant', $conexion->access_token);
        $this->assertNull($conexion->token_expires_at);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/subscribed_apps')
            && $request->hasHeader('Authorization', 'Bearer EAAG-token-de-tenant'));
    }

    public function test_paso_1_fallido_no_persiste_nada_y_da_422(): void
    {
        $this->fakeMeta([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => ['message' => 'code expired']], 400),
        ]);

        try {
            $this->conectar();
            $this->fail('Se esperaba un HttpException 422.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertDatabaseCount('whatsapp_connections', 0);
    }

    public function test_paso_2_fallido_tras_agotar_reintentos_no_persiste_nada_y_da_502(): void
    {
        $this->fakeMeta([
            'graph.facebook.com/*/phone_numbers*' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        try {
            $this->conectar();
            $this->fail('Se esperaba un HttpException 502.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(502, $e->getStatusCode());
        }

        $this->assertDatabaseCount('whatsapp_connections', 0);
    }

    public function test_paso_2_reintenta_hasta_3_veces_ante_fallas_transitorias(): void
    {
        $this->fakeMeta([
            'graph.facebook.com/*/phone_numbers*' => Http::sequence()
                ->push(['error' => ['message' => '503']], 503)
                ->push(['error' => ['message' => '503']], 503)
                ->push([
                    'data' => [[
                        'id' => self::PHONE_NUMBER_ID,
                        'display_phone_number' => '+54 9 11 2233-4455',
                        'verified_name' => 'Salón Demo',
                    ]],
                ], 200),
        ]);

        $this->conectar();

        $this->assertDatabaseCount('whatsapp_connections', 1);

        $intentos = 0;
        Http::assertSent(function ($request) use (&$intentos) {
            if (str_contains($request->url(), '/phone_numbers')) {
                $intentos++;
            }

            return true;
        });
        $this->assertSame(3, $intentos);
    }

    public function test_paso_3_fallido_no_persiste_nada_y_da_502(): void
    {
        $this->fakeMeta([
            'graph.facebook.com/*/subscribed_apps' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        try {
            $this->conectar();
            $this->fail('Se esperaba un HttpException 502.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(502, $e->getStatusCode());
        }

        $this->assertDatabaseCount('whatsapp_connections', 0);
    }

    public function test_multiples_numeros_sin_id_provisto_da_422(): void
    {
        $this->fakeMeta([
            'graph.facebook.com/*/phone_numbers*' => Http::response([
                'data' => [
                    ['id' => '222222222222222', 'display_phone_number' => '+54 9 11 1111-1111', 'verified_name' => 'Uno'],
                    ['id' => '333333333333333', 'display_phone_number' => '+54 9 11 2222-2222', 'verified_name' => 'Dos'],
                ],
            ]),
        ]);

        try {
            $this->conectar(phoneNumberId: null);
            $this->fail('Se esperaba un HttpException 422.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertDatabaseCount('whatsapp_connections', 0);
    }

    public function test_id_provisto_ausente_de_la_lista_da_422(): void
    {
        $this->fakeMeta();

        try {
            $this->conectar(phoneNumberId: '999999999999999');
            $this->fail('Se esperaba un HttpException 422.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertDatabaseCount('whatsapp_connections', 0);
    }

    public function test_lista_de_numeros_vacia_da_422_y_no_persiste_nada(): void
    {
        $this->fakeMeta([
            'graph.facebook.com/*/phone_numbers*' => Http::response(['data' => []]),
        ]);

        try {
            $this->conectar(phoneNumberId: null);
            $this->fail('Se esperaba un HttpException 422.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertDatabaseCount('whatsapp_connections', 0);
    }

    public function test_las_llamadas_http_corren_fuera_de_toda_transaccion_db(): void
    {
        $nivelesTransaccion = [];

        Http::fake(function ($request) use (&$nivelesTransaccion) {
            $nivelesTransaccion[] = DB::transactionLevel();

            return match (true) {
                str_contains($request->url(), '/oauth/access_token') => Http::response([
                    'access_token' => 'EAAG-token-de-tenant',
                    'expires_in' => 0,
                ]),
                str_contains($request->url(), '/phone_numbers') => Http::response([
                    'data' => [[
                        'id' => self::PHONE_NUMBER_ID,
                        'display_phone_number' => '+54 9 11 2233-4455',
                        'verified_name' => 'Salón Demo',
                    ]],
                ]),
                default => Http::response(['success' => true]),
            };
        });

        // Baseline: RefreshDatabase envuelve el test en una transacción, así que
        // el nivel base no es 0. Lo que importa es que las 3 llamadas HTTP NO
        // abran una transacción adicional por encima de ese baseline.
        $baseline = DB::transactionLevel();

        $this->conectar();

        $this->assertCount(3, $nivelesTransaccion);
        $this->assertSame(
            [$baseline, $baseline, $baseline],
            $nivelesTransaccion,
            'Las llamadas a Meta deben correr fuera de toda transacción DB (design §3).',
        );
    }

    public function test_expires_in_ausente_persiste_token_expires_at_null(): void
    {
        $this->fakeMeta([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAAG-token-de-tenant']),
        ]);

        $conexion = $this->conectar();

        $this->assertNull($conexion->token_expires_at);
    }

    public function test_expires_in_positivo_persiste_now_mas_expires_in(): void
    {
        $this->freezeTime();
        $this->fakeMeta([
            'graph.facebook.com/*/oauth/access_token*' => Http::response([
                'access_token' => 'EAAG-token-de-tenant',
                'expires_in' => 5184000,
            ]),
        ]);

        $conexion = $this->conectar();

        $this->assertSame(now()->timestamp + 5184000, $conexion->token_expires_at);
    }

    public function test_expires_in_no_numerico_persiste_expiry_conservador_y_loguea_warning(): void
    {
        $this->freezeTime();
        Log::spy();
        $this->fakeMeta([
            'graph.facebook.com/*/oauth/access_token*' => Http::response([
                'access_token' => 'EAAG-token-de-tenant',
                'expires_in' => 'para-siempre',
            ]),
        ]);

        $conexion = $this->conectar();

        $this->assertSame(now()->timestamp + 3600, $conexion->token_expires_at);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($mensaje) => $mensaje === 'whatsapp.es.expires_in_invalido')
            ->once();
    }

    public function test_expires_in_negativo_persiste_expiry_conservador(): void
    {
        $this->freezeTime();
        $this->fakeMeta([
            'graph.facebook.com/*/oauth/access_token*' => Http::response([
                'access_token' => 'EAAG-token-de-tenant',
                'expires_in' => -10,
            ]),
        ]);

        $conexion = $this->conectar();

        $this->assertSame(now()->timestamp + 3600, $conexion->token_expires_at);
    }

    public function test_phone_number_id_de_otro_salon_lanza_excepcion_tipada_con_solo_el_id(): void
    {
        $otroSalon = User::factory()->create();
        WhatsappConnection::create([
            'user_id' => $otroSalon->id,
            'waba_id' => '444444444444444',
            'phone_number_id' => self::PHONE_NUMBER_ID,
            'display_phone_number' => '5491199887766',
            'verified_name' => 'Salón Preexistente',
            'access_token' => 'EAAG-otro',
            'token_expires_at' => null,
        ]);

        $this->fakeMeta();

        try {
            $this->conectar(User::factory()->create());
            $this->fail('Se esperaba PhoneNumberYaVinculadoException.');
        } catch (PhoneNumberYaVinculadoException $e) {
            $this->assertSame(self::PHONE_NUMBER_ID, $e->phoneNumberId);
            $this->assertStringNotContainsString('Salón Preexistente', $e->getMessage());
        }

        $this->assertDatabaseCount('whatsapp_connections', 1);
    }

    public function test_query_exception_no_relacionada_a_phone_number_id_se_relanza_sin_traducir(): void
    {
        $this->fakeMeta();
        Schema::drop('whatsapp_connections');

        $this->expectException(QueryException::class);

        try {
            $this->conectar();
        } catch (PhoneNumberYaVinculadoException $e) {
            $this->fail('Un deadlock / constraint no relacionada NO debe traducirse a 409.');
        }
    }
}

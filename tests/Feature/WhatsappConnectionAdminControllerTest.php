<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use App\Models\WhatsappConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Superficie HTTP del onboarding de Embedded Signup (design §7).
 *
 * Controller propio (NO un método de AdminController). El GET de estado es
 * INGATEADO — debe seguir alcanzable mientras la feature está gated (Q1) — y
 * nunca llama a conectar(). El POST delega en EmbeddedSignupService::conectar()
 * (que corre la guarda de Advanced Access adentro) y sólo traduce excepciones a
 * HTTP: la colisión cross-salón se convierte en un 409 con el nombre del salón
 * dueño (info sólo-admin, A8); las excepciones de gate ya renderizan como 403
 * por extender HttpException.
 */
class WhatsappConnectionAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    private const WABA_ID = '111111111111111';

    private const PHONE_NUMBER_ID = '222222222222222';

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'name' => 'Superadmin',
            'email' => 'admin@turnetto.app',
            'password' => 'password-de-test',
        ]);

        config([
            'services.whatsapp_es.enabled' => true,
            'services.whatsapp_es.allow_all' => true,
            'services.whatsapp_es.allowed_user_ids' => [],
            'services.whatsapp_es.graph_version' => 'v26.0',
            'services.whatsapp_es.app_id' => 'app-id-123',
            'services.whatsapp_es.app_secret' => 'app-secret-xyz',
            'services.whatsapp_es.config_id' => 'config-id-789',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeMeta(array $overrides = [], string $phoneNumberId = self::PHONE_NUMBER_ID): void
    {
        Http::fake(array_merge([
            'graph.facebook.com/*/oauth/access_token*' => Http::response([
                'access_token' => 'EAAG-token-de-tenant',
                'token_type' => 'bearer',
                'expires_in' => 0,
            ]),
            'graph.facebook.com/*/phone_numbers*' => Http::response([
                'data' => [[
                    'id' => $phoneNumberId,
                    'display_phone_number' => '+54 9 11 2233-4455',
                    'verified_name' => 'Salón Demo',
                ]],
            ]),
            'graph.facebook.com/*/subscribed_apps' => Http::response(['success' => true]),
        ], $overrides));
    }

    private function seedConexion(User $user, string $phoneNumberId): WhatsappConnection
    {
        return WhatsappConnection::create([
            'user_id' => $user->id,
            'waba_id' => self::WABA_ID,
            'phone_number_id' => $phoneNumberId,
            'display_phone_number' => '5491100000000',
            'verified_name' => 'Número previo',
            'access_token' => 'EAAG-token-previo',
            'token_expires_at' => null,
        ]);
    }

    // ── POST /api/admin/whatsapp/connections ─────────────────────

    public function test_post_rechaza_sin_sesion_admin(): void
    {
        $this->postJson('/api/admin/whatsapp/connections', [])->assertStatus(401);
    }

    public function test_post_conecta_y_devuelve_201_sin_exponer_el_token(): void
    {
        $this->fakeMeta();
        $salon = User::factory()->create();

        $response = $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/whatsapp/connections', [
                'user_id' => $salon->id,
                'code' => 'es-code-fresco',
                'waba_id' => self::WABA_ID,
                'phone_number_id' => self::PHONE_NUMBER_ID,
            ])
            ->assertCreated()
            ->assertJsonPath('user_id', $salon->id)
            ->assertJsonPath('phone_number_id', self::PHONE_NUMBER_ID)
            ->assertJsonPath('waba_id', self::WABA_ID);

        $this->assertArrayNotHasKey('access_token', $response->json());
        $this->assertDatabaseHas('whatsapp_connections', [
            'user_id' => $salon->id,
            'phone_number_id' => self::PHONE_NUMBER_ID,
        ]);
    }

    public function test_post_colision_cross_salon_devuelve_409_con_el_nombre_del_dueno(): void
    {
        $dueno = User::factory()->create(['name' => 'Salón Original']);
        $this->seedConexion($dueno, self::PHONE_NUMBER_ID);

        $otroSalon = User::factory()->create();
        $this->fakeMeta();

        $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/whatsapp/connections', [
                'user_id' => $otroSalon->id,
                'code' => 'es-code-fresco',
                'waba_id' => self::WABA_ID,
                'phone_number_id' => self::PHONE_NUMBER_ID,
            ])
            ->assertStatus(409)
            ->assertJsonPath('phone_number_id', self::PHONE_NUMBER_ID)
            ->assertJsonPath('salon_dueno.id', $dueno->id)
            ->assertJsonFragment(['name' => 'Salón Original']);

        $this->assertDatabaseMissing('whatsapp_connections', ['user_id' => $otroSalon->id]);
    }

    public function test_post_gateado_devuelve_403_y_no_llama_a_meta(): void
    {
        config(['services.whatsapp_es.enabled' => false]);
        Http::fake();
        $salon = User::factory()->create();

        $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/whatsapp/connections', [
                'user_id' => $salon->id,
                'code' => 'es-code-fresco',
                'waba_id' => self::WABA_ID,
                'phone_number_id' => self::PHONE_NUMBER_ID,
            ])
            ->assertStatus(403);

        Http::assertNothingSent();
        $this->assertDatabaseCount('whatsapp_connections', 0);
    }

    public function test_post_valida_los_campos_requeridos(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/whatsapp/connections', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id', 'code', 'waba_id']);
    }

    // ── GET /api/admin/whatsapp/connections ──────────────────────

    public function test_get_rechaza_sin_sesion_admin(): void
    {
        $this->getJson('/api/admin/whatsapp/connections')->assertStatus(401);
    }

    public function test_get_devuelve_es_y_salones_incluso_estando_gateado(): void
    {
        config(['services.whatsapp_es.enabled' => false]);

        $conectado = User::factory()->create(['name' => 'Aaa Salón Conectado']);
        $this->seedConexion($conectado, self::PHONE_NUMBER_ID);
        $sinConexion = User::factory()->create(['name' => 'Bbb Salón Sin Conexión']);

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/whatsapp/connections')
            ->assertOk()
            ->assertJsonPath('es.enabled', false)
            ->assertJsonPath('es.app_id', 'app-id-123')
            ->assertJsonPath('es.config_id', 'config-id-789')
            ->assertJsonPath('es.graph_version', 'v26.0');

        $salones = collect($response->json('salones'))->keyBy('user_id');

        $this->assertSame('conectada', $salones[$conectado->id]['estado']);
        $this->assertSame('5491100000000', $salones[$conectado->id]['display_phone_number']);
        $this->assertSame('Número previo', $salones[$conectado->id]['verified_name']);

        $this->assertSame('sin_conexion', $salones[$sinConexion->id]['estado']);
        $this->assertNull($salones[$sinConexion->id]['display_phone_number']);

        foreach ($response->json('salones') as $salon) {
            $this->assertArrayNotHasKey('access_token', $salon);
        }
    }

    public function test_get_no_dispara_el_intercambio_de_embedded_signup(): void
    {
        Http::fake();
        User::factory()->count(2)->create();

        $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/whatsapp/connections')
            ->assertOk();

        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use App\Models\UserMpCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 1 de Mercado Pago: carga manual del access_token por negocio (sin
 * OAuth propio, a diferencia de WhatsApp Embedded Signup). Reemplaza cargar
 * UserMpCredential por `artisan tinker`.
 */
class MercadoPagoAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'name' => 'Superadmin',
            'email' => 'admin@turnetto.app',
            'password' => 'password-de-test',
        ]);
    }

    public function test_index_sin_sesion_admin_es_401(): void
    {
        $this->getJson('/api/admin/mercadopago/connections')->assertStatus(401);
    }

    public function test_index_lista_negocios_con_su_estado_de_conexion(): void
    {
        $sinConectar = User::factory()->create(['name' => 'Nails Studio', 'sena_monto' => null]);
        $conectado = User::factory()->create(['name' => 'Estudio Ana', 'sena_monto' => 5000]);
        $credencial = UserMpCredential::create([
            'user_id' => $conectado->id,
            'mp_access_token' => 'APP_USR-secreto',
            'mp_user_id' => 'MP-1',
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/mercadopago/connections')
            ->assertOk();

        $salones = collect($response->json('salones'))->keyBy('user_id');

        $this->assertFalse($salones[$sinConectar->id]['conectado']);
        $this->assertNull($salones[$sinConectar->id]['webhook_url']);

        $this->assertTrue($salones[$conectado->id]['conectado']);
        $this->assertSame('MP-1', $salones[$conectado->id]['mp_user_id']);
        $this->assertStringContainsString(
            "/api/webhooks/mercadopago/{$credencial->webhook_ruteo}",
            $salones[$conectado->id]['webhook_url'],
        );
        $this->assertSame('5000.00', $salones[$conectado->id]['sena_monto']);
    }

    public function test_index_nunca_expone_el_access_token(): void
    {
        $salon = User::factory()->create();
        UserMpCredential::create([
            'user_id' => $salon->id,
            'mp_access_token' => 'APP_USR-secreto-no-debe-salir',
            'mp_user_id' => 'MP-1',
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/mercadopago/connections')
            ->assertOk();

        $response->assertJsonMissing(['mp_access_token' => 'APP_USR-secreto-no-debe-salir']);
        $this->assertStringNotContainsString('APP_USR-secreto-no-debe-salir', $response->getContent());
    }

    public function test_store_sin_sesion_admin_es_401(): void
    {
        $this->postJson('/api/admin/mercadopago/connections', [])->assertStatus(401);
    }

    public function test_store_crea_la_credencial_con_webhook_ruteo_autogenerado(): void
    {
        $salon = User::factory()->create();

        $response = $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/mercadopago/connections', [
                'user_id' => $salon->id,
                'mp_access_token' => 'APP_USR-token-nuevo',
                'mp_user_id' => 'MP-99',
            ])
            ->assertStatus(201);

        $response->assertJsonMissingPath('mp_access_token');
        $this->assertStringContainsString('/api/webhooks/mercadopago/', $response->json('webhook_url'));

        $credencial = UserMpCredential::where('user_id', $salon->id)->firstOrFail();
        $this->assertSame('MP-99', $credencial->mp_user_id);
        $this->assertSame('APP_USR-token-nuevo', $credencial->mp_access_token);
        $this->assertNotEmpty($credencial->webhook_ruteo);
    }

    public function test_store_sobre_un_negocio_ya_conectado_rota_el_token_sin_cambiar_el_webhook_ruteo(): void
    {
        $salon = User::factory()->create();
        $original = UserMpCredential::create([
            'user_id' => $salon->id,
            'mp_access_token' => 'APP_USR-viejo',
            'mp_user_id' => 'MP-1',
        ]);
        $ruteoOriginal = $original->webhook_ruteo;

        $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/mercadopago/connections', [
                'user_id' => $salon->id,
                'mp_access_token' => 'APP_USR-rotado',
                'mp_user_id' => 'MP-1-nuevo',
            ])
            ->assertStatus(201);

        $this->assertSame(1, UserMpCredential::where('user_id', $salon->id)->count());
        $actualizada = $original->fresh();
        $this->assertSame('APP_USR-rotado', $actualizada->mp_access_token);
        $this->assertSame('MP-1-nuevo', $actualizada->mp_user_id);
        $this->assertSame($ruteoOriginal, $actualizada->webhook_ruteo);
    }

    public function test_store_valida_los_campos_requeridos(): void
    {
        // mp_user_id NO esta acá: es opcional, se deriva solo (ver tests de
        // abajo) — user_id y mp_access_token siguen siendo obligatorios.
        $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/mercadopago/connections', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id', 'mp_access_token']);
    }

    // Bug real evitado: antes había que pegar el access_token a mano, correr
    // GET /users/me por separado (PowerShell/curl) y recién ahí completar
    // mp_user_id en el form. Ahora alcanza con el access_token solo.
    public function test_store_sin_mp_user_id_lo_deriva_llamando_a_users_me(): void
    {
        $salon = User::factory()->create();
        Http::fake(['api.mercadopago.com/users/me' => Http::response(['id' => 123456789], 200)]);

        $response = $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/mercadopago/connections', [
                'user_id' => $salon->id,
                'mp_access_token' => 'APP_USR-token-nuevo',
            ])
            ->assertStatus(201);

        $this->assertSame('123456789', $response->json('mp_user_id'));
        $this->assertSame('123456789', UserMpCredential::where('user_id', $salon->id)->firstOrFail()->mp_user_id);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/users/me')
            && $r->hasHeader('Authorization', 'Bearer APP_USR-token-nuevo'));
    }

    public function test_store_con_mp_user_id_explicito_no_llama_a_mp(): void
    {
        $salon = User::factory()->create();

        $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/mercadopago/connections', [
                'user_id' => $salon->id,
                'mp_access_token' => 'APP_USR-token-nuevo',
                'mp_user_id' => 'MP-a-mano',
            ])
            ->assertStatus(201);

        $this->assertSame('MP-a-mano', UserMpCredential::where('user_id', $salon->id)->firstOrFail()->mp_user_id);
        Http::assertNothingSent();
    }

    public function test_store_con_un_access_token_invalido_no_guarda_nada(): void
    {
        $salon = User::factory()->create();
        Http::fake(['api.mercadopago.com/users/me' => Http::response(['message' => 'invalid token'], 401)]);

        $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/mercadopago/connections', [
                'user_id' => $salon->id,
                'mp_access_token' => 'APP_USR-token-invalido',
            ])
            ->assertStatus(422);

        $this->assertNull(UserMpCredential::where('user_id', $salon->id)->first());
    }

    public function test_store_con_un_user_id_inexistente_es_422(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/mercadopago/connections', [
                'user_id' => 999999,
                'mp_access_token' => 'APP_USR-token',
                'mp_user_id' => 'MP-1',
            ])
            ->assertStatus(422);
    }
}

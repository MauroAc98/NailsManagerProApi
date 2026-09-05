<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappConnection;
use App\Services\CloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * §4 del diseño: la clave de salud se namespacea por phone_number_id de tenant,
 * pero el número compartido (y todo caller sin argumento) sigue en la clave
 * legacy CACHE_KEY_SALUD — backward-compatible por construcción.
 */
class CloudApiSaludPorNumeroTest extends TestCase
{
    use RefreshDatabase;

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

    // §Requirement "Health check reflects the number actually used": un
    // negocio con conexión propia debe quedar gateado por la salud de SU
    // número, no por la del número compartido — incluso cuando divergen.
    public function test_criterio_de_envio_manual_de_un_negocio_conectado_lee_la_clave_de_su_propio_numero(): void
    {
        config(['services.whatsapp_cloud.calidad_bloqueante' => ['RED']]);
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'RED']);
        Cache::forever(CloudApiService::CACHE_KEY_SALUD.':777888999', ['quality_rating' => 'GREEN']);

        $user = User::factory()->create([
            'telefono' => '3765000000',
            'direccion' => 'Calle Falsa 123',
            'locale' => 'es',
        ]);

        WhatsappConnection::create([
            'user_id' => $user->id,
            'waba_id' => '111111111111111',
            'phone_number_id' => '777888999',
            'display_phone_number' => '5491122334455',
            'verified_name' => 'Negocio Demo',
            'access_token' => 'EAAG-secreto-de-tenant',
            'token_expires_at' => null,
        ]);

        // El compartido está en rojo, pero el número propio del negocio
        // está en verde — el negocio conectado NO debe requerir envío
        // manual por ese motivo.
        $this->assertFalse($user->fresh()->whatsapp_requiere_envio_manual);
    }
}

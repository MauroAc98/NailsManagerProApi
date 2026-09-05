<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

// §Design "Credential invariant": credencialesWhatsapp() devuelve
// credenciales compartidas (nulls) SI Y SOLO SI no existe whatsappConnection.
// Una fila existente SIEMPRE devuelve el token/numero propio del tenant,
// incluso cuando esta expirada — estructuralmente imposible facturarle a
// Turnetto el envio de un negocio conectado.
class UserCredencialesWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private function crearConexion(User $user, array $override = []): WhatsappConnection
    {
        return WhatsappConnection::create(array_merge([
            'user_id' => $user->id,
            'waba_id' => '111111111111111',
            'phone_number_id' => '222222222222222',
            'display_phone_number' => '5491122334455',
            'verified_name' => 'Negocio Demo',
            'access_token' => 'EAAG-secreto-de-tenant',
            'token_expires_at' => null,
        ], $override));
    }

    public function test_sin_conexion_devuelve_credenciales_compartidas(): void
    {
        $user = User::factory()->create();

        $credenciales = $user->credencialesWhatsapp();

        $this->assertNull($credenciales['token']);
        $this->assertNull($credenciales['phone_number_id']);
        $this->assertSame('cloud_api', $credenciales['provider']);
    }

    public function test_conexion_conectada_devuelve_credenciales_propias(): void
    {
        $user = User::factory()->create();
        $this->crearConexion($user, ['token_expires_at' => null]);

        $credenciales = $user->fresh()->credencialesWhatsapp();

        $this->assertSame('EAAG-secreto-de-tenant', $credenciales['token']);
        $this->assertSame('222222222222222', $credenciales['phone_number_id']);
        $this->assertSame('cloud_api_tenant', $credenciales['provider']);
    }

    public function test_conexion_por_vencer_devuelve_credenciales_propias(): void
    {
        $user = User::factory()->create();
        $this->crearConexion($user, ['token_expires_at' => now()->addDays(3)->timestamp]);

        $credenciales = $user->fresh()->credencialesWhatsapp();

        $this->assertSame('EAAG-secreto-de-tenant', $credenciales['token']);
        $this->assertSame('222222222222222', $credenciales['phone_number_id']);
        $this->assertSame('cloud_api_tenant', $credenciales['provider']);
    }

    public function test_conexion_expirada_devuelve_credenciales_propias_y_loguea(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $this->crearConexion($user, ['token_expires_at' => now()->subMinute()->timestamp]);

        $credenciales = $user->fresh()->credencialesWhatsapp();

        // Estructuralmente imposible caer al número compartido: la fila
        // existe, así que las credenciales SIEMPRE son las del tenant.
        $this->assertSame('EAAG-secreto-de-tenant', $credenciales['token']);
        $this->assertSame('222222222222222', $credenciales['phone_number_id']);
        $this->assertSame('cloud_api_tenant', $credenciales['provider']);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('whatsapp.tenant.token_expirado_en_envio', ['user_id' => $user->id]);
    }
}

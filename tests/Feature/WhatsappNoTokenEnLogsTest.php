<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappConnection;
use App\Services\CloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

// §Success Criterion 8: ningún log introducido por este cambio contiene el
// token en texto plano — ni el compartido, ni el de un tenant.
class WhatsappNoTokenEnLogsTest extends TestCase
{
    use RefreshDatabase;

    private function assertNingunLogContieneElToken(string $token): void
    {
        Log::shouldNotHaveReceived('error', fn (string $mensaje, array $contexto = []) => str_contains(json_encode($contexto), $token) || str_contains($mensaje, $token));
        Log::shouldNotHaveReceived('warning', fn (string $mensaje, array $contexto = []) => str_contains(json_encode($contexto), $token) || str_contains($mensaje, $token));
        Log::shouldNotHaveReceived('info', fn (string $mensaje, array $contexto = []) => str_contains(json_encode($contexto), $token) || str_contains($mensaje, $token));
    }

    public function test_enviarplantilla_no_loguea_el_token_cuando_meta_rechaza_el_envio(): void
    {
        Log::spy();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid parameter']], 400),
        ]);

        app(CloudApiService::class)->enviarPlantilla(
            '5493765123456',
            'recordatorio_turno',
            'es_AR',
            ['Martina'],
            token: 'token-secreto-de-tenant',
            phoneNumberId: '999888777666555',
        );

        $this->assertNingunLogContieneElToken('token-secreto-de-tenant');
    }

    public function test_credenciales_whatsapp_no_loguea_el_token_de_una_conexion_expirada(): void
    {
        Log::spy();

        $user = User::factory()->create();
        WhatsappConnection::create([
            'user_id' => $user->id,
            'waba_id' => '111111111111111',
            'phone_number_id' => '222222222222222',
            'display_phone_number' => '5491122334455',
            'verified_name' => 'Negocio Demo',
            'access_token' => 'token-secreto-expirado',
            'token_expires_at' => now()->subMinute()->timestamp,
        ]);

        $user->fresh()->credencialesWhatsapp();

        $this->assertNingunLogContieneElToken('token-secreto-expirado');
    }

    public function test_el_job_de_confirmacion_no_loguea_el_token_de_tenant_en_un_envio_exitoso(): void
    {
        Log::spy();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '+543765111111',
            'direccion' => 'Calle Falsa 123',
            'confirmacion_automatica' => true,
        ]);

        WhatsappConnection::create([
            'user_id' => $user->id,
            'waba_id' => '111111111111111',
            'phone_number_id' => 'numero-del-tenant',
            'display_phone_number' => '5491122334455',
            'verified_name' => 'Negocio Demo',
            'access_token' => 'token-del-tenant-en-el-job',
            'token_expires_at' => null,
        ]);

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '+543765252395',
        ]);

        $turno = Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addHours(2),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        $this->assertNingunLogContieneElToken('token-del-tenant-en-el-job');
    }
}

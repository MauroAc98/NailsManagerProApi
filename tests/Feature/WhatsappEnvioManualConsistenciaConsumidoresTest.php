<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappConnection;
use App\Models\WhatsappMensaje;
use App\Services\CloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// Cubre el requerimiento "Consistent Gating Across Both Send Consumers": el
// job de confirmación y el comando de recordatorios deben quedar gateados
// de forma idéntica ante el mismo estado de usuario, sin divergencia entre
// los dos call sites. Un mismo usuario, con un único motivo para el flag
// (veredicto de salud cacheado en rojo), procesado por ambos consumidores
// en el mismo test.
class WhatsappEnvioManualConsistenciaConsumidoresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(CloudApiService::CACHE_KEY_SALUD);
    }

    public function test_job_y_comando_quedan_gateados_igual_cuando_la_salud_cacheada_esta_en_rojo(): void
    {
        Http::fake();
        Mail::fake();
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'RED']);

        // Teléfono y dirección completos, locale por defecto -> el único
        // motivo del flag para este usuario es el veredicto de salud
        // cacheado en rojo, compartido por los dos consumidores.
        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '3765000000',
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
        ]);

        $this->assertTrue($user->whatsapp_requiere_envio_manual);

        $clienteConfirmacion = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '+543765252395',
        ]);

        $turnoConfirmacion = Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $clienteConfirmacion->id,
            'fecha_hora' => now()->addHours(2),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        $clienteRecordatorio = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Beti',
            'apellido' => 'Diaz',
            'telefono' => '3765252396',
        ]);

        Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $clienteRecordatorio->id,
            'fecha_hora' => now()->addDay()->setTime(10, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        (new EnviarMensajeConfirmacion($turnoConfirmacion->id))->handle(app(CloudApiService::class));
        $this->artisan('recordatorios:enviar');

        // Ninguno de los dos consumidores debe haber intentado un envío
        // automático — ambos leen el mismo atributo, gateado por la misma
        // señal de salud.
        Http::assertNothingSent();

        $this->assertSame(
            0,
            WhatsappMensaje::whereIn('tipo', ['confirmacion', 'recordatorio'])->count(),
        );
    }

    private function crearNegocio(array $override = []): User
    {
        return User::factory()->create(array_merge([
            'is_exempt' => true,
            'telefono' => '3765000000',
            'direccion' => 'Calle Falsa 123',
            'confirmacion_automatica' => true,
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
        ], $override));
    }

    private function crearConexion(User $user, array $override = []): WhatsappConnection
    {
        return WhatsappConnection::create(array_merge([
            'user_id' => $user->id,
            'waba_id' => '111111111111111',
            'phone_number_id' => '222222222222222',
            'display_phone_number' => '5491122334455',
            'verified_name' => 'Negocio Demo',
            'access_token' => 'token-de-tenant',
            'token_expires_at' => null,
        ], $override));
    }

    private function crearTurnoConfirmacion(User $user, string $telefono): Turno
    {
        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Cliente',
            'apellido' => 'Confirmacion',
            'telefono' => $telefono,
        ]);

        return Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addHours(2),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
    }

    private function crearTurnoRecordatorio(User $user, string $telefono): Turno
    {
        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Cliente',
            'apellido' => 'Recordatorio',
            'telefono' => $telefono,
        ]);

        return Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addDay()->setTime(10, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
    }

    // MANDATORY anti-drift test (§Testing Strategy): un batch mixto — negocio
    // A sin conexión (número compartido), negocio B con conexión sana
    // (número propio) y negocio C con conexión expirada (nunca cae al
    // compartido) — procesado en UNA sola corrida de recordatorios:enviar Y
    // a través del Job, sobre la MISMA instancia compartida de
    // CloudApiService. Verifica por-request cuál número/token se usó y que
    // C no manda nada por el número compartido.
    public function test_batch_mixto_de_tres_negocios_rutea_cada_envio_por_su_propio_numero_sin_fuga_de_estado(): void
    {
        config([
            'services.whatsapp_cloud.token' => 'token-compartido',
            'services.whatsapp_cloud.phone_number_id' => 'numero-compartido',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.OK']],
            ], 200),
        ]);
        Mail::fake();

        $negocioA = $this->crearNegocio(); // sin conexión -> compartido
        $negocioB = $this->crearNegocio(); // conexión sana -> número propio
        $this->crearConexion($negocioB, ['phone_number_id' => 'numero-propio-b', 'access_token' => 'token-propio-b']);
        $negocioC = $this->crearNegocio(); // conexión expirada -> nunca compartido
        $this->crearConexion($negocioC, [
            'phone_number_id' => 'numero-propio-c',
            'access_token' => 'token-propio-c',
            'token_expires_at' => now()->subMinute()->timestamp,
        ]);

        $this->assertFalse($negocioA->whatsapp_requiere_envio_manual);
        $this->assertFalse($negocioB->fresh()->whatsapp_requiere_envio_manual);
        $this->assertTrue($negocioC->fresh()->whatsapp_requiere_envio_manual);

        // ── Job (confirmación) — mismo turno x negocio ──
        $turnoConfA = $this->crearTurnoConfirmacion($negocioA, '+543765111111');
        $turnoConfB = $this->crearTurnoConfirmacion($negocioB, '+543765222222');
        $turnoConfC = $this->crearTurnoConfirmacion($negocioC, '+543765333333');

        (new EnviarMensajeConfirmacion($turnoConfA->id))->handle(app(CloudApiService::class));
        (new EnviarMensajeConfirmacion($turnoConfB->id))->handle(app(CloudApiService::class));
        (new EnviarMensajeConfirmacion($turnoConfC->id))->handle(app(CloudApiService::class));

        // ── Comando (recordatorios) — mismo CloudApiService compartido entre los 3 ──
        $this->crearTurnoRecordatorio($negocioA, '+543765444444');
        $this->crearTurnoRecordatorio($negocioB, '+543765555555');
        $this->crearTurnoRecordatorio($negocioC, '+543765666666');

        $this->artisan('recordatorios:enviar');

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/'.config('services.whatsapp_cloud.api_version').'/numero-compartido/messages'
            && $request->hasHeader('Authorization', 'Bearer token-compartido')
            && str_contains(json_encode($request->data()), '3765111111'));

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/'.config('services.whatsapp_cloud.api_version').'/numero-propio-b/messages'
            && $request->hasHeader('Authorization', 'Bearer token-propio-b')
            && str_contains(json_encode($request->data()), '3765222222'));

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/'.config('services.whatsapp_cloud.api_version').'/numero-compartido/messages'
            && $request->hasHeader('Authorization', 'Bearer token-compartido')
            && str_contains(json_encode($request->data()), '3765444444'));

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/'.config('services.whatsapp_cloud.api_version').'/numero-propio-b/messages'
            && $request->hasHeader('Authorization', 'Bearer token-propio-b')
            && str_contains(json_encode($request->data()), '3765555555'));

        // Negocio C: ni el número compartido ni ningún otro número recibió
        // nada en su nombre — el gate cortó el envío antes de llegar a
        // enviarPlantilla().
        Http::assertNotSent(fn ($request) => str_contains(json_encode($request->data()), '3765333333')
            || str_contains(json_encode($request->data()), '3765666666'));

        $this->assertSame(
            'cloud_api',
            WhatsappMensaje::where('turno_id', $turnoConfA->id)->value('provider'),
        );
        $this->assertSame(
            'cloud_api_tenant',
            WhatsappMensaje::where('turno_id', $turnoConfB->id)->value('provider'),
        );
        $this->assertSame(
            0,
            WhatsappMensaje::where('turno_id', $turnoConfC->id)->count(),
        );
        $this->assertSame(
            0,
            WhatsappMensaje::where('user_id', $negocioC->id)->where('tipo', 'recordatorio')->count(),
        );
    }
}

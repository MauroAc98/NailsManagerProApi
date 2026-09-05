<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Cliente;
use App\Models\Subscription;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappConnection;
use App\Models\WhatsappMensaje;
use App\Services\CloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnviarMensajeConfirmacionAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurno(User $user): Turno
    {
        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '+543765252395',
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

    public function test_no_envia_confirmacion_cuando_confirmacion_automatica_esta_apagada(): void
    {
        Http::fake();

        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '+543765111111',
            'confirmacion_automatica' => false,
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertNothingSent();

        $this->assertSame(
            0,
            WhatsappMensaje::where('turno_id', $turno->id)->count(),
        );
    }

    public function test_envia_confirmacion_normal_cuando_confirmacion_automatica_esta_prendida(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TEST123']],
            ], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '+543765111111',
            'confirmacion_automatica' => true,
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));

        $this->assertSame(
            1,
            WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'confirmacion')->count(),
        );
    }

    public function test_no_envia_confirmacion_cuando_la_suscripcion_esta_vencida(): void
    {
        Http::fake();

        $user = User::factory()->create([
            'is_exempt' => false,
            'telefono' => '+543765111111',
            'confirmacion_automatica' => true,
        ]);
        Subscription::create(['user_id' => $user->id, 'ends_at' => now()->subDay(), 'status' => 'VENCIDO']);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertNothingSent();
        $this->assertSame(0, WhatsappMensaje::where('turno_id', $turno->id)->count());
    }

    public function test_no_envia_confirmacion_cuando_la_suscripcion_esta_suspendida(): void
    {
        Http::fake();

        $user = User::factory()->create([
            'is_exempt' => false,
            'telefono' => '+543765111111',
            'confirmacion_automatica' => true,
        ]);
        // ends_at en el futuro: solo la suspensión debe cortar el envío pago.
        Subscription::create(['user_id' => $user->id, 'ends_at' => now()->addDays(10), 'status' => 'SUSPENDIDO']);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertNothingSent();
        $this->assertSame(0, WhatsappMensaje::where('turno_id', $turno->id)->count());
    }

    public function test_no_envia_confirmacion_sin_ninguna_suscripcion_cargada(): void
    {
        Http::fake();

        $user = User::factory()->create([
            'is_exempt' => false,
            'telefono' => '+543765111111',
            'confirmacion_automatica' => true,
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertNothingSent();
    }

    public function test_envia_confirmacion_con_suscripcion_vigente_y_cuenta_no_exenta(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TEST789']],
            ], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => false,
            'telefono' => '+543765111111',
            'confirmacion_automatica' => true,
        ]);
        Subscription::create(['user_id' => $user->id, 'ends_at' => now()->addDays(10), 'status' => 'ACTIVO']);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    // §Requirement "Tenant-routed send when connection is healthy" /
    // "Shared-number send unaffected (regression)".
    public function test_envia_confirmacion_por_el_numero_compartido_cuando_no_hay_conexion(): void
    {
        config([
            'services.whatsapp_cloud.token' => 'token-compartido',
            'services.whatsapp_cloud.phone_number_id' => 'numero-compartido',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.SHARED1']],
            ], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '+543765111111',
            'direccion' => 'Calle Falsa 123',
            'confirmacion_automatica' => true,
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/'.config('services.whatsapp_cloud.api_version').'/numero-compartido/messages'
            && $request->hasHeader('Authorization', 'Bearer token-compartido'));

        $this->assertSame(
            'cloud_api',
            WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'confirmacion')->value('provider'),
        );
    }

    public function test_envia_confirmacion_por_el_numero_propio_cuando_hay_conexion_conectada(): void
    {
        config(['services.whatsapp_cloud.token' => 'token-compartido']);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TENANT1']],
            ], 200),
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
            'access_token' => 'token-de-tenant',
            'token_expires_at' => null,
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/'.config('services.whatsapp_cloud.api_version').'/numero-del-tenant/messages'
            && $request->hasHeader('Authorization', 'Bearer token-de-tenant'));

        $this->assertSame(
            'cloud_api_tenant',
            WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'confirmacion')->value('provider'),
        );
    }

    public function test_envia_confirmacion_por_defecto_sin_setear_confirmacion_automatica(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TEST456']],
            ], 200),
        ]);

        // No se setea confirmacion_automatica explícitamente -> default true.
        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '+543765111111',
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }
}

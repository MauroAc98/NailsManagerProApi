<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Cliente;
use App\Models\Subscription;
use App\Models\Turno;
use App\Models\User;
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
            'direccion' => 'San Martin 123',
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
            'direccion' => 'San Martin 123',
            'confirmacion_automatica' => true,
        ]);
        Subscription::create(['user_id' => $user->id, 'ends_at' => now()->addDays(10), 'status' => 'ACTIVO']);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
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
            'direccion' => 'San Martin 123',
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }
}

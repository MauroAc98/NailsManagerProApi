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
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class EnviarMensajeConfirmacionSenaTest extends TestCase
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

    private function parametrosDe($request): array
    {
        return $request->data()['template']['components'][0]['parameters'];
    }

    public function test_usa_la_plantilla_de_sena_cuando_el_salon_pide_sena(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SENA1']]], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '+543765111111',
            'confirmacion_automatica' => true,
            'whatsapp_pide_sena' => true,
            'sena_monto' => 5000,
            'whatsapp_sena_titular' => 'Kimberley Faustino',
            'whatsapp_sena_alias' => 'Kim1710',
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertSent(function ($request) {
            return $request->data()['template']['name'] === 'reserva_turno_sena'
                && count($this->parametrosDe($request)) === 10;
        });

        // El registro de tracking sigue siendo categoría 'confirmacion'.
        $this->assertSame(
            1,
            WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'confirmacion')->count(),
        );
    }

    public function test_usa_la_plantilla_de_confirmacion_normal_cuando_el_salon_no_pide_sena(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.NORMAL1']]], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '+543765111111',
            'confirmacion_automatica' => true,
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertSent(function ($request) {
            return $request->data()['template']['name'] === 'confirmacion_turno'
                && count($this->parametrosDe($request)) === 8;
        });
    }

    public function test_cae_a_confirmacion_simple_si_pide_sena_pero_la_config_esta_incompleta(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.FALLBACK1']]], 200),
        ]);
        Log::spy();

        // whatsapp_pide_sena quedó en true por una edición directa de DB,
        // pero sin monto — reserva_turno_sena renderizaría "$0,00".
        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '+543765111111',
            'confirmacion_automatica' => true,
            'whatsapp_pide_sena' => true,
            'sena_monto' => null,
            'whatsapp_sena_titular' => 'Kimberley Faustino',
            'whatsapp_sena_alias' => 'Kim1710',
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertSent(function ($request) {
            return $request->data()['template']['name'] === 'confirmacion_turno'
                && count($this->parametrosDe($request)) === 8;
        });

        Log::shouldHaveReceived('warning')->once();

        $this->assertSame(
            1,
            WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'confirmacion')->count(),
        );
    }

    public function test_los_guards_previos_cortan_antes_de_elegir_la_plantilla_de_sena(): void
    {
        Http::fake();

        $user = User::factory()->create([
            'is_exempt' => false,
            'telefono' => '+543765111111',
            'confirmacion_automatica' => true,
            'whatsapp_pide_sena' => true,
            'sena_monto' => 5000,
            'whatsapp_sena_titular' => 'Kimberley Faustino',
            'whatsapp_sena_alias' => 'Kim1710',
        ]);
        Subscription::create(['user_id' => $user->id, 'ends_at' => now()->subDay(), 'status' => 'VENCIDO']);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertNothingSent();
        $this->assertSame(0, WhatsappMensaje::where('turno_id', $turno->id)->count());
    }

    // §Slice B: `tieneUbicacion` deriva a la vez el nombre de plantilla Y el
    // header — nunca pueden desacoplarse.
    public function test_con_coordenadas_usa_la_plantilla_mapa_y_agrega_el_header(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.MAPA1']]], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '+543765111111',
            'confirmacion_automatica' => true,
            'latitud' => -27.4692,
            'longitud' => -58.8306,
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertSent(function ($request) {
            $componentes = $request->data()['template']['components'];

            return $request->data()['template']['name'] === 'confirmacion_turno_mapa'
                && count($componentes) === 2
                && $componentes[0]['type'] === 'header'
                && $componentes[0]['parameters'][0]['location']['latitude'] === '-27.4692'
                && $componentes[1]['type'] === 'body';
        });
    }

    public function test_sin_coordenadas_usa_la_plantilla_legacy_y_no_agrega_header(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.LEGACY1']]], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '+543765111111',
            'confirmacion_automatica' => true,
            'latitud' => null,
            'longitud' => null,
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertSent(function ($request) {
            $componentes = $request->data()['template']['components'];

            return $request->data()['template']['name'] === 'confirmacion_turno'
                && count($componentes) === 1
                && $componentes[0]['type'] === 'body';
        });
    }

    public function test_reserva_sena_con_coordenadas_usa_la_plantilla_sena_mapa_y_agrega_el_header(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SENAMAPA1']]], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '+543765111111',
            'confirmacion_automatica' => true,
            'whatsapp_pide_sena' => true,
            'sena_monto' => 5000,
            'whatsapp_sena_titular' => 'Kimberley Faustino',
            'whatsapp_sena_alias' => 'Kim1710',
            'latitud' => -27.4692,
            'longitud' => -58.8306,
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertSent(function ($request) {
            $componentes = $request->data()['template']['components'];

            return $request->data()['template']['name'] === 'reserva_turno_sena_mapa'
                && $componentes[0]['type'] === 'header';
        });
    }

    public function test_downgrade_de_sena_con_coordenadas_sigue_usando_confirmacion_mapa(): void
    {
        // whatsapp_pide_sena activo con config incompleta (sin monto) sigue
        // haciendo downgrade a 'confirmacion' — con coordenadas eso ahora
        // significa confirmacion_turno_mapa, no confirmacion_turno a secas.
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.DOWNGRADE1']]], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '+543765111111',
            'confirmacion_automatica' => true,
            'whatsapp_pide_sena' => true,
            'sena_monto' => null,
            'whatsapp_sena_titular' => 'Kimberley Faustino',
            'whatsapp_sena_alias' => 'Kim1710',
            'latitud' => -27.4692,
            'longitud' => -58.8306,
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertSent(function ($request) {
            return $request->data()['template']['name'] === 'confirmacion_turno_mapa';
        });
    }

    public function test_el_envio_manual_corta_antes_de_elegir_la_plantilla_de_sena(): void
    {
        Http::fake();

        // telefono vacío ⇒ whatsapp_requiere_envio_manual = true
        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '',
            'confirmacion_automatica' => true,
            'whatsapp_pide_sena' => true,
            'sena_monto' => 5000,
            'whatsapp_sena_titular' => 'Kimberley Faustino',
            'whatsapp_sena_alias' => 'Kim1710',
        ]);

        $turno = $this->crearTurno($user);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertNothingSent();
        $this->assertSame(0, WhatsappMensaje::where('turno_id', $turno->id)->count());
    }
}

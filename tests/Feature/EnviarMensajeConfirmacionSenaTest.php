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

<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\PagoSena;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\User;
use App\Models\UserMpCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El webhook NUNCA confia en el cuerpo de la notificacion: siempre vuelve a
 * consultar el pago real a la API de MP (con el access_token del negocio
 * identificado por el ruteo de la URL) antes de confirmar nada.
 */
class MercadoPagoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private UserMpCredential $credencial;

    private ReservaWeb $reserva;

    private PagoSena $pago;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_exempt' => true, 'sena_monto' => 5000]);
        $this->credencial = UserMpCredential::create([
            'user_id' => $this->user->id,
            'mp_access_token' => 'APP_USR-token-de-natalia',
            'mp_user_id' => 'MP-1',
        ]);

        $profesional = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'activo' => true]);
        $servicio = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Mani', 'duracion_minutos' => 30, 'precio' => 1000, 'activo' => true]);
        $profesional->servicios()->attach($servicio->id);
        SlotDisponible::create(['user_id' => $this->user->id, 'profesional_id' => $profesional->id, 'hora' => '10:00:00', 'activo' => true]);

        $this->reserva = ReservaWeb::create([
            'user_id' => $this->user->id,
            'profesional_id' => $profesional->id,
            'public_token' => ReservaWeb::generarToken(),
            'servicio_ids' => [$servicio->id],
            'fecha' => now()->addDay()->toDateString(),
            'slot_hora' => '10:00',
            'duracion_total_minutos' => 30,
            'estado' => 'pending_payment',
            'nombre' => 'Lucia',
            'apellido' => 'Gomez',
            'telefono' => '+5491155551234',
            'expira_en' => now()->addMinutes(15)->timestamp,
        ]);

        $this->pago = PagoSena::create([
            'reserva_web_id' => $this->reserva->id,
            'mp_preference_id' => 'PREF-1',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF-1',
            'monto' => 5000,
            'estado' => 'pendiente',
        ]);
    }

    private function notificar(?string $ruteo = null, string $paymentId = 'PAY-1'): TestResponse
    {
        return $this->postJson(
            '/api/webhooks/mercadopago/'.($ruteo ?? $this->credencial->webhook_ruteo).'?data.id='.$paymentId,
            ['action' => 'payment.updated', 'type' => 'payment', 'data' => ['id' => $paymentId]],
        );
    }

    private function fakePayment(string $status, array $extra = [], string $paymentId = 'PAY-1'): void
    {
        Http::fake(["api.mercadopago.com/v1/payments/{$paymentId}*" => Http::response(array_merge([
            'id' => $paymentId,
            'status' => $status,
            'external_reference' => $this->reserva->public_token,
        ], $extra), 200)]);
    }

    public function test_un_ruteo_desconocido_es_404_y_no_llama_a_mp(): void
    {
        $this->notificar('ruteo-que-no-existe')->assertStatus(404);

        Http::assertNothingSent();
    }

    public function test_pago_aprobado_confirma_el_turno_usando_el_token_del_negocio_correcto(): void
    {
        $this->fakePayment('approved');

        $this->notificar()->assertOk();

        $this->assertSame('aprobado', $this->pago->fresh()->estado);
        $this->assertSame('PAY-1', $this->pago->fresh()->mp_payment_id);
        $this->assertSame('confirmed', $this->reserva->fresh()->estado);
        $this->assertSame(1, Turno::count());

        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer APP_USR-token-de-natalia'));
    }

    public function test_pago_pendiente_no_confirma_nada(): void
    {
        $this->fakePayment('pending');

        $this->notificar()->assertOk();

        $this->assertSame('pendiente', $this->pago->fresh()->estado);
        $this->assertSame('pending_payment', $this->reserva->fresh()->estado);
        $this->assertSame(0, Turno::count());
    }

    public function test_pago_rechazado_no_confirma_nada(): void
    {
        $this->fakePayment('rejected');

        $this->notificar()->assertOk();

        $this->assertSame('rechazado', $this->pago->fresh()->estado);
        $this->assertSame(0, Turno::count());
    }

    public function test_una_notificacion_repetida_del_mismo_pago_aprobado_no_duplica_el_turno(): void
    {
        $this->fakePayment('approved');

        $this->notificar()->assertOk();
        $this->notificar()->assertOk();

        $this->assertSame(1, Turno::count());
    }

    public function test_sin_data_id_responde_200_sin_llamar_a_mp(): void
    {
        $this->postJson('/api/webhooks/mercadopago/'.$this->credencial->webhook_ruteo, ['type' => 'payment'])->assertOk();

        Http::assertNothingSent();
    }

    public function test_un_topico_que_no_es_payment_se_ignora_sin_llamar_a_mp(): void
    {
        $this->postJson(
            '/api/webhooks/mercadopago/'.$this->credencial->webhook_ruteo.'?data.id=X',
            ['type' => 'subscription_preapproval', 'data' => ['id' => 'X']],
        )->assertOk();

        Http::assertNothingSent();
    }

    public function test_si_la_consulta_a_mp_falla_responde_500_para_que_reintente(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/*' => Http::response(['message' => 'boom'], 500)]);

        $this->notificar()->assertStatus(500);

        $this->assertSame('pendiente', $this->pago->fresh()->estado);
    }

    public function test_un_external_reference_de_otra_reserva_no_rompe_y_no_confirma_nada(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/*' => Http::response([
            'id' => 'PAY-1', 'status' => 'approved', 'external_reference' => 'token-que-no-existe',
        ], 200)]);

        $this->notificar()->assertOk();

        $this->assertSame(0, Turno::count());
        $this->assertSame('pendiente', $this->pago->fresh()->estado);
    }

    public function test_un_external_reference_de_una_reserva_de_otro_negocio_se_ignora(): void
    {
        $otro = User::factory()->create(['is_exempt' => true]);
        $reservaAjena = ReservaWeb::create([
            'user_id' => $otro->id,
            'public_token' => ReservaWeb::generarToken(),
            'servicio_ids' => [1],
            'fecha' => now()->addDay()->toDateString(),
            'slot_hora' => '11:00',
            'duracion_total_minutos' => 30,
            'estado' => 'pending_payment',
            'expira_en' => now()->addMinutes(15)->timestamp,
        ]);
        Http::fake(['api.mercadopago.com/v1/payments/*' => Http::response([
            'id' => 'PAY-1', 'status' => 'approved', 'external_reference' => $reservaAjena->public_token,
        ], 200)]);

        $this->notificar()->assertOk();

        $this->assertSame(0, Turno::count());
        $this->assertSame('pending_payment', $reservaAjena->fresh()->estado);
    }

    public function test_pago_aprobado_pero_el_horario_ya_no_esta_libre_marca_requiere_reembolso_sin_crear_turno(): void
    {
        // Otro turno confirmado pisa el mismo horario mientras esperaba el pago.
        Turno::create([
            'user_id' => $this->user->id,
            'profesional_id' => $this->reserva->profesional_id,
            'cliente_id' => Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Otra', 'telefono' => '3765000000'])->id,
            'fecha_hora' => substr((string) $this->reserva->getRawOriginal('fecha'), 0, 10).' 10:00:00',
            'duracion_total_minutos' => 30,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
        $this->fakePayment('approved');

        $this->notificar()->assertOk();

        $this->assertSame('aprobado', $this->pago->fresh()->estado);
        $this->assertSame(1, Turno::count()); // el que ya estaba, no uno nuevo
        $this->assertTrue((bool) $this->reserva->fresh()->requiere_reembolso);
    }
}

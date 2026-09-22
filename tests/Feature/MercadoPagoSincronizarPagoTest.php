<?php

namespace Tests\Feature;

use App\Exceptions\ReservaPublicaException;
use App\Models\PagoSena;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\User;
use App\Models\UserMpCredential;
use App\Services\Reservas\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * consultarPago / buscarPagoPorExternalReference / sincronizarPago: la logica
 * compartida entre el webhook (avisos que llegan solos) y la reconciliacion
 * (que vuelve a preguntarle a MP por los que quedaron pendientes) — un unico
 * lugar que decide que hacer con un pago, para que las dos vias nunca se
 * desalineen.
 */
class MercadoPagoSincronizarPagoTest extends TestCase
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

    private function datosPago(string $status, float $monto = 5000, array $extra = []): array
    {
        return array_merge([
            'id' => 'PAY-1',
            'status' => $status,
            'transaction_amount' => $monto,
            'external_reference' => $this->reserva->public_token,
        ], $extra);
    }

    public function test_sincronizar_con_pago_aprobado_confirma_el_turno(): void
    {
        app(MercadoPagoService::class)->sincronizarPago($this->pago, $this->reserva, $this->datosPago('approved'));

        $this->assertSame('aprobado', $this->pago->fresh()->estado);
        $this->assertSame('PAY-1', $this->pago->fresh()->mp_payment_id);
        $this->assertSame('confirmed', $this->reserva->fresh()->estado);
        $this->assertSame(1, Turno::count());
    }

    public function test_sincronizar_dos_veces_con_aprobado_no_duplica_el_turno(): void
    {
        $svc = app(MercadoPagoService::class);
        $svc->sincronizarPago($this->pago, $this->reserva, $this->datosPago('approved'));
        $svc->sincronizarPago($this->pago->fresh(), $this->reserva->fresh(), $this->datosPago('approved'));

        $this->assertSame(1, Turno::count());
    }

    // QA: defensa contra un monto que no coincide con lo que se le pidio a
    // MP (nunca deberia pasar salvo un bug o algo raro) — no confirma a ciegas.
    public function test_un_monto_pagado_distinto_al_esperado_no_confirma_nada(): void
    {
        app(MercadoPagoService::class)->sincronizarPago($this->pago, $this->reserva, $this->datosPago('approved', 1.0));

        $this->assertSame('pendiente', $this->pago->fresh()->estado);
        $this->assertSame(0, Turno::count());
    }

    public function test_pago_pendiente_actualiza_el_estado_sin_confirmar(): void
    {
        app(MercadoPagoService::class)->sincronizarPago($this->pago, $this->reserva, $this->datosPago('pending'));

        $this->assertSame('pendiente', $this->pago->fresh()->estado);
        $this->assertSame(0, Turno::count());
    }

    public function test_pago_rechazado_actualiza_el_estado(): void
    {
        app(MercadoPagoService::class)->sincronizarPago($this->pago, $this->reserva, $this->datosPago('rejected'));

        $this->assertSame('rechazado', $this->pago->fresh()->estado);
    }

    public function test_consultar_pago_devuelve_los_datos_reales(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/PAY-1*' => Http::response($this->datosPago('approved'), 200)]);

        $datos = app(MercadoPagoService::class)->consultarPago($this->credencial, 'PAY-1');

        $this->assertSame('approved', $datos['status']);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer APP_USR-token-de-natalia'));
    }

    public function test_consultar_pago_lanza_mp_error_si_mp_falla(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/PAY-1*' => Http::response(['message' => 'boom'], 500)]);

        $this->expectException(ReservaPublicaException::class);
        app(MercadoPagoService::class)->consultarPago($this->credencial, 'PAY-1');
    }

    public function test_buscar_por_external_reference_devuelve_el_primer_resultado(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response([
            'results' => [$this->datosPago('approved')],
        ], 200)]);

        $datos = app(MercadoPagoService::class)->buscarPagoPorExternalReference($this->credencial, $this->reserva->public_token);

        $this->assertSame('PAY-1', $datos['id']);
        Http::assertSent(fn (HttpRequest $r) => ($r->data()['external_reference'] ?? null) === $this->reserva->public_token);
    }

    public function test_buscar_por_external_reference_sin_resultados_devuelve_null(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []], 200)]);

        $this->assertNull(app(MercadoPagoService::class)->buscarPagoPorExternalReference($this->credencial, 'token-sin-pago'));
    }

    public function test_buscar_por_external_reference_si_mp_falla_devuelve_null_sin_lanzar(): void
    {
        // A diferencia de consultarPago (usado por el webhook, que necesita
        // fallar fuerte para que MP reintente), la reconciliacion recorre
        // muchas filas: una falla puntual no debe tumbar el resto del lote.
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['message' => 'boom'], 500)]);

        $this->assertNull(app(MercadoPagoService::class)->buscarPagoPorExternalReference($this->credencial, 'x'));
    }
}

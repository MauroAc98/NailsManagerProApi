<?php

namespace Tests\Feature;

use App\Models\PagoSena;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\User;
use App\Models\UserMpCredential;
use App\Services\Reservas\ReconciliarPagosService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El webhook puede no llegar nunca: caida nuestra, caida de MP, o simplemente
 * MP no lo mando. Este servicio vuelve a preguntarle a MP por los pagos que
 * quedaron 'pendiente' hace rato, usando la MISMA logica de sincronizarPago
 * que el webhook (MercadoPagoService) para no divergir.
 */
class ReconciliarPagosServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private UserMpCredential $credencial;

    private ReservaWeb $reserva;

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
    }

    private function pagoPendiente(array $attrs = []): PagoSena
    {
        $pago = PagoSena::create(array_merge([
            'reserva_web_id' => $this->reserva->id,
            'mp_preference_id' => 'PREF-1',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF-1',
            'monto' => 5000,
            'estado' => 'pendiente',
        ], $attrs));

        // created_at se pisa aparte: CreatedAt no es fillable y el factory de
        // arriba siempre usa "ahora" — la reconciliacion solo debe tocar
        // pagos con margen suficiente para que el webhook ya haya podido llegar.
        $pago->forceFill(['created_at' => now()->subMinutes(5)])->save();

        return $pago->fresh();
    }

    public function test_pago_pendiente_reciente_no_se_toca_para_darle_tiempo_al_webhook(): void
    {
        $pago = $this->pagoPendiente();
        $pago->forceFill(['created_at' => now()->subMinute()])->save();
        Http::fake(['api.mercadopago.com/*' => Http::response(['status' => 'approved'], 200)]);

        $n = app(ReconciliarPagosService::class)->reconciliarPendientes();

        $this->assertSame(0, $n);
        Http::assertNothingSent();
    }

    public function test_pago_pendiente_con_payment_id_conocido_se_reconsulta_y_se_aprueba(): void
    {
        $pago = $this->pagoPendiente(['mp_payment_id' => 'PAY-1']);
        Http::fake(['api.mercadopago.com/v1/payments/PAY-1*' => Http::response([
            'id' => 'PAY-1',
            'status' => 'approved',
            'transaction_amount' => 5000,
            'external_reference' => $this->reserva->public_token,
        ], 200)]);

        $n = app(ReconciliarPagosService::class)->reconciliarPendientes();

        $this->assertSame(1, $n);
        $this->assertSame('aprobado', $pago->fresh()->estado);
        $this->assertSame('confirmed', $this->reserva->fresh()->estado);
        $this->assertSame(1, Turno::count());
    }

    public function test_pago_pendiente_sin_payment_id_busca_por_external_reference(): void
    {
        $pago = $this->pagoPendiente();
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response([
            'results' => [[
                'id' => 'PAY-9',
                'status' => 'approved',
                'transaction_amount' => 5000,
                'external_reference' => $this->reserva->public_token,
            ]],
        ], 200)]);

        $n = app(ReconciliarPagosService::class)->reconciliarPendientes();

        $this->assertSame(1, $n);
        $this->assertSame('aprobado', $pago->fresh()->estado);
        $this->assertSame('PAY-9', $pago->fresh()->mp_payment_id);
    }

    public function test_si_mp_no_tiene_registro_del_pago_sigue_pendiente_sin_romper(): void
    {
        $pago = $this->pagoPendiente();
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []], 200)]);

        $n = app(ReconciliarPagosService::class)->reconciliarPendientes();

        $this->assertSame(0, $n);
        $this->assertSame('pendiente', $pago->fresh()->estado);
    }

    public function test_un_pago_ya_aprobado_no_se_vuelve_a_consultar(): void
    {
        $this->pagoPendiente(['estado' => 'aprobado', 'mp_payment_id' => 'PAY-1']);
        Http::fake(['api.mercadopago.com/*' => Http::response(['status' => 'approved'], 200)]);

        app(ReconciliarPagosService::class)->reconciliarPendientes();

        Http::assertNothingSent();
    }

    public function test_una_falla_de_mp_en_una_fila_no_frena_el_resto_del_lote(): void
    {
        SlotDisponible::create(['user_id' => $this->user->id, 'profesional_id' => $this->reserva->profesional_id, 'hora' => '11:00:00', 'activo' => true]);
        $reserva2 = ReservaWeb::create(array_merge($this->reserva->only([
            'user_id', 'profesional_id', 'servicio_ids', 'fecha',
            'duracion_total_minutos', 'estado', 'nombre', 'apellido', 'telefono', 'expira_en',
        ]), ['slot_hora' => '11:00', 'public_token' => ReservaWeb::generarToken()]));
        $pago1 = $this->pagoPendiente(['mp_payment_id' => 'PAY-1']);
        $pago2 = PagoSena::create([
            'reserva_web_id' => $reserva2->id,
            'mp_preference_id' => 'PREF-2',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF-2',
            'monto' => 5000,
            'estado' => 'pendiente',
            'mp_payment_id' => 'PAY-2',
        ]);
        $pago2->forceFill(['created_at' => now()->subMinutes(5)])->save();

        Http::fake([
            'api.mercadopago.com/v1/payments/PAY-1*' => Http::response(['message' => 'boom'], 500),
            'api.mercadopago.com/v1/payments/PAY-2*' => Http::response([
                'id' => 'PAY-2',
                'status' => 'approved',
                'transaction_amount' => 5000,
                'external_reference' => $reserva2->public_token,
            ], 200),
        ]);

        $n = app(ReconciliarPagosService::class)->reconciliarPendientes();

        $this->assertSame(1, $n);
        $this->assertSame('pendiente', $pago1->fresh()->estado);
        $this->assertSame('aprobado', $pago2->fresh()->estado);
    }

    public function test_el_comando_reconcilia_y_esta_agendado_cada_minuto_sin_solaparse(): void
    {
        $pago = $this->pagoPendiente(['mp_payment_id' => 'PAY-1']);
        Http::fake(['api.mercadopago.com/v1/payments/PAY-1*' => Http::response([
            'id' => 'PAY-1',
            'status' => 'approved',
            'transaction_amount' => 5000,
            'external_reference' => $this->reserva->public_token,
        ], 200)]);

        $this->artisan('pagos:reconciliar')->assertSuccessful();

        $this->assertSame('aprobado', $pago->fresh()->estado);

        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'pagos:reconciliar'));
        $this->assertNotNull($evento, 'falta la entrada en el scheduler');
        $this->assertSame('* * * * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping);
    }
}

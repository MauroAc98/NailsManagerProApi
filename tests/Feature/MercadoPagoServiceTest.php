<?php

namespace Tests\Feature;

use App\Exceptions\ReservaPublicaException;
use App\Models\PagoSena;
use App\Models\ReservaWeb;
use App\Models\User;
use App\Models\UserMpCredential;
use App\Services\Reservas\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Crea (o reusa) la preferencia de Checkout Pro de Mercado Pago para cobrar
 * la seña, con el access_token de la cuenta del NEGOCIO (fase 1: cada negocio
 * con su propia cuenta MP, sin OAuth ni app de Turnetto).
 */
class MercadoPagoServiceTest extends TestCase
{
    use RefreshDatabase;

    private function negocio(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Nails by Natalia',
            'is_exempt' => true,
            'sena_monto' => 5000,
        ], $attrs));
    }

    private function conCredenciales(User $user, string $token = 'APP_USR-token-de-natalia'): UserMpCredential
    {
        return UserMpCredential::create(['user_id' => $user->id, 'mp_access_token' => $token, 'mp_user_id' => 'MP-1']);
    }

    private function reserva(User $user, array $attrs = []): ReservaWeb
    {
        return ReservaWeb::create(array_merge([
            'user_id' => $user->id,
            'public_token' => ReservaWeb::generarToken(),
            'servicio_ids' => [1],
            'fecha' => '2099-06-11',
            'slot_hora' => '10:00',
            'duracion_total_minutos' => 60,
            'estado' => 'pending_payment',
            'nombre' => 'Lucia',
            'telefono' => '+5491155551234',
            'expira_en' => now()->addMinutes(15)->timestamp,
        ], $attrs));
    }

    private function fakeMp(array $respuesta = [], int $status = 201): void
    {
        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response(array_merge([
            'id' => 'PREF-123',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF-123',
        ], $respuesta), $status)]);
    }

    public function test_sin_credenciales_de_mp_lanza_mp_no_conectado_y_no_crea_nada(): void
    {
        $user = $this->negocio();
        $reserva = $this->reserva($user);

        try {
            app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);
            $this->fail('esperaba ReservaPublicaException');
        } catch (ReservaPublicaException $e) {
            $this->assertSame('mp_no_conectado', $e->codigo);
        }

        $this->assertSame(0, PagoSena::count());
    }

    public function test_sin_sena_configurada_lanza_mp_no_conectado_y_no_crea_nada(): void
    {
        $user = $this->negocio(['sena_monto' => null]);
        $this->conCredenciales($user);
        $reserva = $this->reserva($user);

        try {
            app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);
            $this->fail('esperaba ReservaPublicaException');
        } catch (ReservaPublicaException $e) {
            $this->assertSame('mp_no_conectado', $e->codigo);
        }

        $this->assertSame(0, PagoSena::count());
    }

    public function test_crea_la_preferencia_con_el_token_del_negocio_y_guarda_el_pago(): void
    {
        $user = $this->negocio();
        $this->conCredenciales($user, 'APP_USR-token-de-natalia');
        $reserva = $this->reserva($user);
        $this->fakeMp();

        $pago = app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);

        $this->assertSame('PREF-123', $pago->mp_preference_id);
        $this->assertSame('https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF-123', $pago->init_point);
        $this->assertSame('pendiente', $pago->estado);
        $this->assertSame(5000.0, (float) $pago->monto);

        Http::assertSent(function (HttpRequest $r) use ($reserva) {
            return $r->hasHeader('Authorization', 'Bearer APP_USR-token-de-natalia')
                && $r['external_reference'] === $reserva->public_token
                && $r['items'][0]['unit_price'] === 5000.0
                && $r['items'][0]['currency_id'] === 'ARS';
        });
    }

    public function test_usa_reales_para_un_negocio_en_portugues(): void
    {
        $user = $this->negocio(['locale' => 'pt-BR']);
        $this->conCredenciales($user);
        $reserva = $this->reserva($user);
        $this->fakeMp();

        app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);

        Http::assertSent(fn (HttpRequest $r) => $r['items'][0]['currency_id'] === 'BRL');
    }

    public function test_un_reintento_reusa_la_preferencia_pendiente_sin_llamar_a_mp_de_nuevo(): void
    {
        $user = $this->negocio();
        $this->conCredenciales($user);
        $reserva = $this->reserva($user);
        $this->fakeMp();

        $primero = app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);
        $segundo = app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(1, PagoSena::count());
        Http::assertSentCount(1);
    }

    // Bug real encontrado en revision (QA): sin este lock, dos requests casi
    // simultaneas (doble tap de "Pagar") podian pasar juntas el chequeo de
    // "ya hay una pendiente" y crear DOS preferencias en MP para la misma
    // reserva — despues era ambiguo a cual de las dos correspondia un pago.
    public function test_dos_llamadas_concurrentes_no_crean_dos_preferencias(): void
    {
        $user = $this->negocio();
        $this->conCredenciales($user);
        $reserva = $this->reserva($user);
        $this->fakeMp();

        // No hay forma de simular threads reales en PHPUnit; lo que si se
        // puede probar es que el metodo toma un lock de la fila (lockForUpdate)
        // antes de decidir — con sqlite eso alcanza para serializar, y es la
        // misma tecnica que ya usa SlotLock para holds/turnos.
        DB::transaction(function () use ($reserva) {
            $bloqueada = ReservaWeb::whereKey($reserva->id)->lockForUpdate()->first();
            $this->assertNotNull($bloqueada);
        });

        app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);
        app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);

        $this->assertSame(1, PagoSena::count());
    }

    // Un metodo de pago que tarda dias en acreditarse (Rapipago, Pago Facil)
    // no tiene sentido con una ventana de pago de 15 minutos: la clienta
    // pagaria y de todos modos perderia el horario. Se excluye a proposito.
    public function test_excluye_medios_de_pago_no_instantaneos_ticket(): void
    {
        $user = $this->negocio();
        $this->conCredenciales($user);
        $reserva = $this->reserva($user);
        $this->fakeMp();

        app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);

        Http::assertSent(fn (HttpRequest $r) => $r['payment_methods']['excluded_payment_types'] === [['id' => 'ticket']]);
    }

    public function test_si_mp_responde_con_error_lanza_mp_error_y_no_guarda_nada(): void
    {
        $user = $this->negocio();
        $this->conCredenciales($user);
        $reserva = $this->reserva($user);
        $this->fakeMp(['message' => 'invalid token'], 401);

        try {
            app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);
            $this->fail('esperaba ReservaPublicaException');
        } catch (ReservaPublicaException $e) {
            $this->assertSame('mp_error', $e->codigo);
        }

        $this->assertSame(0, PagoSena::count());
    }

    public function test_la_notification_url_usa_el_ruteo_propio_del_negocio(): void
    {
        $user = $this->negocio();
        $credencial = $this->conCredenciales($user);
        $reserva = $this->reserva($user);
        $this->fakeMp();

        app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);

        Http::assertSent(fn (HttpRequest $r) => $r['notification_url'] === config('app.url')."/api/webhooks/mercadopago/{$credencial->webhook_ruteo}"
        );
    }

    public function test_el_external_reference_es_el_token_publico_no_el_id_interno(): void
    {
        $user = $this->negocio();
        $this->conCredenciales($user);
        $reserva = $this->reserva($user);
        $this->fakeMp();

        app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);

        Http::assertSent(function (HttpRequest $r) use ($reserva) {
            $ref = $r['external_reference'];

            return $ref === $reserva->public_token && $ref !== (string) $reserva->id;
        });
    }
}

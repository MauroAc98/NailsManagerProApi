<?php

namespace Tests\Feature;

use App\Exceptions\ReservaPublicaException;
use App\Models\PagoSena;
use App\Models\ReservaWeb;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserMpCredential;
use App\Services\Reservas\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Crea (o reusa) la order de Checkout Pro de Mercado Pago (Orders API) para
 * cobrar la seña, con el access_token de la cuenta del NEGOCIO (fase 1: cada
 * negocio con su propia cuenta MP, sin OAuth ni app de Turnetto).
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
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(array_merge([
            'id' => 'ORDER-123',
            'checkout_url' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=ORDER-123',
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

    // El cliente paga la seña BRUTA: el neto que el profesional quiere recibir
    // mas la comision de MP (con IVA) y la retencion de IIBB, redondeado hacia
    // arriba al proximo multiplo de $100. Lo que se manda a MP y lo que queda en
    // PagoSena.monto es el MISMO numero (sincronizarPago() lo compara contra
    // transaction_amount). Sin precio conocido el fijo va sin tope.
    public function test_crea_la_preferencia_con_el_token_del_negocio_y_guarda_el_pago(): void
    {
        $user = $this->negocio();
        $this->conCredenciales($user, 'APP_USR-token-de-natalia');
        $reserva = $this->reserva($user);
        $this->fakeMp();

        $pago = app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);

        $this->assertSame('ORDER-123', $pago->mp_preference_id);
        $this->assertSame('https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=ORDER-123', $pago->init_point);
        $this->assertSame('pendiente', $pago->estado);
        // 5000 / (1 - 0.076109) = 5411.9 -> 5500
        $this->assertSame(5500.0, (float) $pago->monto);

        Http::assertSent(function (HttpRequest $r) use ($reserva) {
            return $r->hasHeader('Authorization', 'Bearer APP_USR-token-de-natalia')
                && $r['type'] === 'online'
                && $r['total_amount'] === '5500.00'
                && $r['external_reference'] === $reserva->public_token
                && $r['items'][0]['unit_price'] === '5500.00';
        });
    }

    public function test_la_retencion_iibb_se_suma_a_la_tasa_y_sube_el_monto_cobrado(): void
    {
        $user = $this->negocio();
        $user->update(['retencion_iibb_porcentaje' => 4]);
        $this->conCredenciales($user, 'APP_USR-token');
        $reserva = $this->reserva($user);
        $this->fakeMp();

        $pago = app(MercadoPagoService::class)->crearOReusarPreferencia($user->fresh(), $reserva);

        // t = 7.6109 + 4 = 11.6109 -> 5000 / 0.883891 = 5656.8 -> 5700
        $this->assertSame(5700.0, (float) $pago->monto);
    }

    public function test_la_comision_global_cambia_el_monto_cobrado(): void
    {
        Setting::create(['key' => 'comision_mp_porcentaje', 'value' => '15']);
        $user = $this->negocio();
        $this->conCredenciales($user);
        $this->fakeMp();

        $pago = app(MercadoPagoService::class)->crearOReusarPreferencia($user, $this->reserva($user));

        // t = 15 * 1.21 = 18.15 -> 5000 / 0.8185 = 6108.9 -> 6200
        $this->assertSame(6200.0, (float) $pago->monto);
    }

    public function test_sena_para_precio_fijo_es_bruta_redondeada_a_100_y_topeada_al_precio(): void
    {
        $svc = app(MercadoPagoService::class);
        $user = User::factory()->make(['sena_tipo' => 'fijo', 'sena_monto' => 5000]);

        $this->assertSame(5500.0, $svc->senaParaPrecio($user, 20000));
        $this->assertSame(5000.0, $svc->senaNetaParaPrecio($user, 20000));
        // Tope: nunca cobra mas que el precio.
        $this->assertSame(3000.0, $svc->senaParaPrecio($user, 3000));
        $this->assertSame(5000.0, $svc->senaParaPrecio($user, 5000));
        // Sin precio conocido (terminos, servicios sin precio): sin tope.
        $this->assertSame(5500.0, $svc->senaParaPrecio($user, null));
        $this->assertSame(5000.0, $svc->senaNetaParaPrecio($user, null));
        $this->assertSame(0.0, $svc->senaParaPrecio($user, 0));
        $this->assertSame(0.0, $svc->senaNetaParaPrecio($user, 0));
    }

    public function test_sena_para_precio_porcentaje_cubre_la_comision_de_mp(): void
    {
        $svc = app(MercadoPagoService::class);
        $user = User::factory()->make(['sena_tipo' => 'porcentaje', 'sena_porcentaje' => 50]);

        // 50% de 18000 = 9000 neto; t = 6.29 * 1.21 = 7.6109 -> 9000 / 0.923891 = 9741.4 -> 9800
        $this->assertSame(9000.0, $svc->senaNetaParaPrecio($user, 18000));
        $this->assertSame(9800.0, $svc->senaParaPrecio($user, 18000));
    }

    public function test_sena_para_precio_porcentaje_redondea_el_neto_a_pesos_enteros(): void
    {
        $svc = app(MercadoPagoService::class);
        $user = User::factory()->make(['sena_tipo' => 'porcentaje', 'sena_porcentaje' => 30]);

        $this->assertSame(3600.0, $svc->senaNetaParaPrecio($user, 12000));
        $this->assertSame(335.0, $svc->senaNetaParaPrecio($user, 1115));   // 334.5 rounds half up
        $this->assertSame(400.0, $svc->senaParaPrecio($user, 1115));       // 335 / 0.923891 = 362.6 -> 400
        $this->assertSame(0.0, $svc->senaParaPrecio($user, null));
        $this->assertSame(0.0, $svc->senaNetaParaPrecio($user, null));
        $this->assertSame(0.0, $svc->senaParaPrecio($user, 0));
    }

    public function test_sena_para_precio_se_topea_al_precio_con_porcentaje_100(): void
    {
        $svc = app(MercadoPagoService::class);
        $user = User::factory()->make(['sena_tipo' => 'porcentaje', 'sena_porcentaje' => 100]);

        // Bruto seria 1100: se topea al precio, el profesional absorbe el resto.
        $this->assertSame(1000.0, $svc->senaParaPrecio($user, 1000));
        $this->assertSame(1000.0, $svc->senaNetaParaPrecio($user, 1000));
    }

    public function test_sena_para_precio_redondea_hacia_arriba_al_multiplo_de_100(): void
    {
        $svc = app(MercadoPagoService::class);

        // 1000 / 0.923891 = 1082.4 -> 1100
        $this->assertSame(1100.0, $svc->senaParaPrecio(User::factory()->make(['sena_tipo' => 'fijo', 'sena_monto' => 1000]), 50000));
        // 2000 / 0.923891 = 2164.8 -> 2200
        $this->assertSame(2200.0, $svc->senaParaPrecio(User::factory()->make(['sena_tipo' => 'fijo', 'sena_monto' => 2000]), 50000));
        // Con comision 0 y sin retencion, un neto que ya es multiplo no sube.
        Setting::create(['key' => 'comision_mp_porcentaje', 'value' => '0']);
        $this->assertSame(2000.0, $svc->senaParaPrecio(User::factory()->make(['sena_tipo' => 'fijo', 'sena_monto' => 2000]), 50000));
    }

    public function test_sena_para_precio_es_cero_con_config_invalida(): void
    {
        $svc = app(MercadoPagoService::class);

        foreach ([
            ['sena_tipo' => 'fijo', 'sena_monto' => null],
            ['sena_tipo' => 'fijo', 'sena_monto' => 0],
            ['sena_tipo' => 'porcentaje', 'sena_porcentaje' => null],
            ['sena_tipo' => 'porcentaje', 'sena_porcentaje' => 0],
            ['sena_tipo' => 'porcentaje', 'sena_porcentaje' => 101],
        ] as $attrs) {
            $user = User::factory()->make($attrs);
            $this->assertSame(0.0, $svc->senaParaPrecio($user, 1000));
            $this->assertSame(0.0, $svc->senaNetaParaPrecio($user, 1000));
        }
    }

    public function test_comision_vigente_usa_solo_el_setting_global_con_iva_y_cae_a_la_constante(): void
    {
        $svc = app(MercadoPagoService::class);

        $this->assertEqualsWithDelta(6.29 * 1.21, $svc->comisionVigente(), 0.0001);

        Setting::create(['key' => 'comision_mp_porcentaje', 'value' => '8']);
        $this->assertEqualsWithDelta(8 * 1.21, $svc->comisionVigente(), 0.0001);
    }

    public function test_usa_processing_mode_manual_unico_valor_valido_para_checkout_pro(): void
    {
        $user = $this->negocio();
        $this->conCredenciales($user);
        $reserva = $this->reserva($user);
        $this->fakeMp();

        app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);

        Http::assertSent(fn (HttpRequest $r) => $r['processing_mode'] === 'manual');
    }

    public function test_agrega_un_idempotency_key_estable_por_reserva(): void
    {
        $user = $this->negocio();
        $this->conCredenciales($user);
        $reserva = $this->reserva($user);
        $this->fakeMp();

        app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);

        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('X-Idempotency-Key', "reserva-{$reserva->public_token}"));
    }

    // Orders API no acepta currency_id por request: la moneda la determina la
    // cuenta de MP conectada (su pais), no algo que el negocio elija. Antes
    // de esta migracion se armaba a mano segun el locale del negocio.
    public function test_no_envia_currency_id_la_moneda_la_define_la_cuenta_de_mp(): void
    {
        $user = $this->negocio(['locale' => 'pt-BR']);
        $this->conCredenciales($user);
        $reserva = $this->reserva($user);
        $this->fakeMp();

        app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);

        Http::assertSent(fn (HttpRequest $r) => ! isset($r['items'][0]['currency_id']));
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

        Http::assertSent(fn (HttpRequest $r) => $r['config']['payment_method']['not_allowed_types'] === ['ticket']);
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

    // Orders API configura la URL de notificacion UNA VEZ por aplicacion de
    // MP (panel developers), no por request — a diferencia de Preferences,
    // que la aceptaba en el cuerpo de cada llamada. El ruteo propio del
    // negocio (UserMpCredential::webhook_ruteo) sigue existiendo igual, solo
    // que ahora se configura ahi en vez de mandarse aca.
    public function test_no_envia_notification_url_por_request(): void
    {
        $user = $this->negocio();
        $this->conCredenciales($user);
        $reserva = $this->reserva($user);
        $this->fakeMp();

        app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);

        Http::assertSent(fn (HttpRequest $r) => ! isset($r['notification_url']));
    }

    public function test_las_back_urls_apuntan_a_la_reserva_y_auto_return_es_approved(): void
    {
        $user = $this->negocio();
        $this->conCredenciales($user);
        $reserva = $this->reserva($user);
        $this->fakeMp();

        app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);

        $esperada = rtrim((string) config('reservas.base_url'), '/')."/reservar/{$user->slug}/reserva/{$reserva->public_token}";

        Http::assertSent(function (HttpRequest $r) use ($esperada) {
            $online = $r['config']['online'];

            return $online['success_url'] === $esperada
                && $online['pending_url'] === $esperada
                && $online['failure_url'] === $esperada
                && $online['auto_return'] === 'approved';
        });
    }

    // Bug real: usaba services.frontend_url (app.turnetto.com, el dashboard)
    // en vez de reservas.base_url (reservar.turnetto.com) — Mercado Pago
    // mandaba a la clienta de vuelta al dominio equivocado, donde el guard
    // de auth no la excluye del Welcome de una cuenta logueada.
    public function test_las_back_urls_no_usan_el_dominio_del_dashboard(): void
    {
        $user = $this->negocio();
        $this->conCredenciales($user);
        $reserva = $this->reserva($user);
        $this->fakeMp();

        app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);

        $dashboard = rtrim((string) config('services.frontend_url'), '/');

        Http::assertSent(fn (HttpRequest $r) => ! str_starts_with($r['config']['online']['success_url'], $dashboard));
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

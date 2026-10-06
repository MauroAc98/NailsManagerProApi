<?php

namespace Tests\Feature;

use App\Exceptions\ReservaPublicaException;
use App\Models\PagoSena;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\User;
use App\Models\UserMpCredential;
use App\Services\Reservas\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

/** Seña como porcentaje del total de la reserva (users.sena_tipo = porcentaje). */
class SenaPorcentajeTest extends TestCase
{
    use CreaSalonPublico, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['reservas.creacion_habilitada' => true]);
    }

    private function salonPct(array $attrs = [], bool $mp = true): User
    {
        $user = $this->crearSalon(array_merge([
            'sena_tipo' => 'porcentaje', 'sena_porcentaje' => 30, 'sena_monto' => null,
        ], $attrs));
        if ($mp) {
            UserMpCredential::create(['user_id' => $user->id, 'mp_access_token' => 'APP_USR-x', 'mp_user_id' => 'MP-1']);
        }

        return $user;
    }

    private function reserva(User $user, array $servicioIds, array $attrs = []): ReservaWeb
    {
        return ReservaWeb::create(array_merge([
            'user_id' => $user->id, 'public_token' => ReservaWeb::generarToken(), 'servicio_ids' => $servicioIds,
            'fecha' => '2099-06-11', 'slot_hora' => '10:00:00', 'duracion_total_minutos' => 60,
            'estado' => 'pending_payment', 'nombre' => 'Lucia', 'telefono' => '+5491155551234',
            'expira_en' => now()->addMinutes(15)->timestamp,
        ], $attrs));
    }

    private function servicioConPrecio(User $user, ?float $precio): Servicio
    {
        return Servicio::create(['user_id' => $user->id, 'nombre' => 'X'.uniqid(), 'duracion_minutos' => 30, 'precio' => $precio, 'activo' => true]);
    }

    private function fakeMp(): void
    {
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response([
            'id' => 'ORDER-1', 'checkout_url' => 'https://mp.test/checkout',
        ], 201)]);
    }

    public function test_defaults_de_las_columnas_nuevas(): void
    {
        $user = User::factory()->create()->fresh();

        $this->assertSame('fijo', $user->sena_tipo);
        $this->assertNull($user->sena_porcentaje);
    }

    // -- MercadoPagoService ------------------------------------------

    public function test_cobra_el_porcentaje_con_gross_up_redondeado_a_100(): void
    {
        $user = $this->salonPct();
        $s = $this->crearServicio($user); // 12000
        $this->fakeMp();
        $svc = app(MercadoPagoService::class);

        $pago = $svc->crearOReusarPreferencia($user, $this->reserva($user, [$s->id]));

        // neto 12000 * 30% = 3600; 3600 / 0.923891 = 3896.5 -> 3900.
        $this->assertEquals(3900, $pago->monto);
    }

    public function test_50_por_ciento_de_18000_cobra_9800_y_el_cap_al_precio_con_100(): void
    {
        $user = $this->salonPct(['sena_porcentaje' => 50]);
        $s = $this->servicioConPrecio($user, 18000);
        $this->fakeMp();
        $svc = app(MercadoPagoService::class);

        $pago = $svc->crearOReusarPreferencia($user, $this->reserva($user, [$s->id]));

        // neto 9000; t = 7.6109 -> 9741.4 -> 9800
        $this->assertEquals(9800, $pago->monto);

        $user2 = $this->salonPct(['sena_porcentaje' => 100]);
        $s2 = $this->servicioConPrecio($user2, 18000);
        $pago2 = $svc->crearOReusarPreferencia($user2, $this->reserva($user2, [$s2->id]));

        // Gross would exceed the price: capped, the professional absorbs the rest.
        $this->assertEquals(18000, $pago2->monto);
    }

    public function test_la_sena_se_redondea_a_pesos_enteros(): void
    {
        $user = $this->salonPct(['sena_porcentaje' => 33.33]);
        $s = $this->servicioConPrecio($user, 1001);
        $this->fakeMp();
        $svc = app(MercadoPagoService::class);

        $pago = $svc->crearOReusarPreferencia($user, $this->reserva($user, [$s->id]));

        // neto: 1001 * 33.33 / 100 = 333.633 -> 334 (pesos enteros);
        // cobrado: 334 / 0.923891 = 361.5 -> 400.
        $this->assertEquals(400, $pago->monto);
    }

    public function test_sin_total_no_cobra_y_no_crea_pago_ni_llama_a_mp(): void
    {
        $user = $this->salonPct();
        $sinPrecio = $this->servicioConPrecio($user, null);
        Http::fake();

        try {
            app(MercadoPagoService::class)->crearOReusarPreferencia($user, $this->reserva($user, [$sinPrecio->id]));
            $this->fail('esperaba ReservaPublicaException');
        } catch (ReservaPublicaException $e) {
            $this->assertSame('sena_sin_total', $e->codigo);
            $this->assertSame(422, $e->status);
        }

        $this->assertSame(0, PagoSena::count());
        Http::assertNothingSent();
    }

    public function test_sena_cero_por_redondeo_tambien_es_sena_sin_total(): void
    {
        $user = $this->salonPct(['sena_porcentaje' => 1]);
        $s = $this->servicioConPrecio($user, 10);
        Http::fake();

        $this->expectException(ReservaPublicaException::class);
        app(MercadoPagoService::class)->crearOReusarPreferencia($user, $this->reserva($user, [$s->id]));
    }

    public function test_porcentaje_sin_valor_configurado_es_mp_no_conectado(): void
    {
        $user = $this->salonPct(['sena_porcentaje' => null]);
        $s = $this->crearServicio($user);
        Http::fake();

        try {
            app(MercadoPagoService::class)->crearOReusarPreferencia($user, $this->reserva($user, [$s->id]));
            $this->fail('esperaba ReservaPublicaException');
        } catch (ReservaPublicaException $e) {
            $this->assertSame('mp_no_conectado', $e->codigo);
        }
        $this->assertSame(0, PagoSena::count());
    }

    public function test_reusa_el_pago_pendiente_con_el_monto_congelado_aunque_cambie_la_config(): void
    {
        $user = $this->salonPct();
        $s = $this->crearServicio($user);
        $this->fakeMp();
        $svc = app(MercadoPagoService::class);
        $reserva = $this->reserva($user, [$s->id]);

        $primero = $svc->crearOReusarPreferencia($user, $reserva);
        $user->update(['sena_porcentaje' => 80]);
        $segundo = $svc->crearOReusarPreferencia($user->fresh(), $reserva);

        $this->assertSame($primero->id, $segundo->id);
        $this->assertEquals($primero->monto, $segundo->monto);
        $this->assertSame(1, PagoSena::count());
    }

    public function test_modo_fijo_sigue_igual(): void
    {
        $user = $this->crearSalon(['sena_monto' => 5000]);
        UserMpCredential::create(['user_id' => $user->id, 'mp_access_token' => 'APP_USR-x', 'mp_user_id' => 'MP-1']);
        $s = $this->crearServicio($user);
        $this->fakeMp();
        $svc = app(MercadoPagoService::class);

        $pago = $svc->crearOReusarPreferencia($user, $this->reserva($user, [$s->id]));

        // 5000 / 0.923891 = 5411.9 -> 5500
        $this->assertEquals(5500, $pago->monto);
    }

    public function test_fijo_se_topea_al_precio_de_la_reserva(): void
    {
        $user = $this->crearSalon(['sena_monto' => 5000]);
        UserMpCredential::create(['user_id' => $user->id, 'mp_access_token' => 'APP_USR-x', 'mp_user_id' => 'MP-1']);
        $barato = $this->servicioConPrecio($user, 3000);
        $this->fakeMp();

        $pago = app(MercadoPagoService::class)->crearOReusarPreferencia($user, $this->reserva($user, [$barato->id]));

        $this->assertEquals(3000, $pago->monto);
    }

    public function test_el_cobro_congela_el_precio_total_de_la_reserva(): void
    {
        $user = $this->salonPct();
        $s = $this->crearServicio($user); // 12000
        $this->fakeMp();
        $reserva = $this->reserva($user, [$s->id]);

        app(MercadoPagoService::class)->crearOReusarPreferencia($user, $reserva);

        $this->assertSame(12000, $reserva->fresh()->precio_total);
    }

    public function test_show_usa_el_precio_congelado_aunque_cambie_el_servicio(): void
    {
        $user = $this->salonPct();
        $s = $this->crearServicio($user); // 12000
        $reserva = $this->reserva($user, [$s->id], ['estado' => 'held', 'precio_total' => 12000]);
        $s->update(['precio' => 20000]);

        $this->getJson("/api/public/{$user->slug}/reservas/{$reserva->public_token}", ['X-Device-Token' => 'device-token-de-prueba-0123456789abcdef'])
            ->assertOk()
            ->assertJsonPath('resumen.deposito', 3900);
    }

    // -- Endpoints publicos ------------------------------------------

    public function test_info_pago_habilitado_es_mode_aware(): void
    {
        $pct = $this->salonPct();
        $this->getJson("/api/public/{$pct->slug}/info")->assertOk()->assertJsonPath('pago_habilitado', true);

        $sinPct = $this->salonPct(['sena_porcentaje' => null]);
        $this->getJson("/api/public/{$sinPct->slug}/info")->assertOk()->assertJsonPath('pago_habilitado', false);

        $sinMp = $this->salonPct([], false);
        $this->getJson("/api/public/{$sinMp->slug}/info")->assertOk()->assertJsonPath('pago_habilitado', false);

        // Modo fijo con un porcentaje viejo guardado: manda sena_monto.
        $fijo = $this->crearSalon(['sena_tipo' => 'fijo', 'sena_monto' => null, 'sena_porcentaje' => 30]);
        UserMpCredential::create(['user_id' => $fijo->id, 'mp_access_token' => 'APP_USR-x', 'mp_user_id' => 'MP-1']);
        $this->getJson("/api/public/{$fijo->slug}/info")->assertOk()->assertJsonPath('pago_habilitado', false);
    }

    public function test_terminos_en_porcentaje_no_rompe_y_avisa_el_modo(): void
    {
        $user = $this->salonPct();

        $this->getJson("/api/public/{$user->slug}/terminos")
            ->assertOk()
            ->assertJsonPath('deposito', null)
            ->assertJsonPath('sena_tipo', 'porcentaje')
            ->assertJsonPath('sena_porcentaje', 30);
    }

    public function test_terminos_en_fijo_no_agrega_campos(): void
    {
        $user = $this->crearSalon(['sena_monto' => 5000]);

        $this->getJson("/api/public/{$user->slug}/terminos")
            ->assertOk()
            ->assertJsonMissingPath('sena_tipo');
    }

    public function test_show_devuelve_el_monto_del_pago_existente(): void
    {
        $user = $this->salonPct();
        $s = $this->crearServicio($user);
        $reserva = $this->reserva($user, [$s->id]);
        PagoSena::create(['reserva_web_id' => $reserva->id, 'mp_preference_id' => 'P', 'init_point' => 'u', 'monto' => 4100, 'estado' => 'pendiente']);
        $user->update(['sena_porcentaje' => 90]); // cambio posterior: no debe afectar lo ya cobrado

        $this->getJson("/api/public/{$user->slug}/reservas/{$reserva->public_token}", ['X-Device-Token' => 'device-token-de-prueba-0123456789abcdef'])
            ->assertOk()
            ->assertJsonPath('resumen.deposito', 4100);
    }

    public function test_show_sin_pago_calcula_con_la_config_actual_en_porcentaje(): void
    {
        $user = $this->salonPct();
        $s = $this->crearServicio($user); // 12000
        $reserva = $this->reserva($user, [$s->id], ['estado' => 'held']);
        $esperado = 3900; // 3600 neto grossed up and rounded to 100

        $this->getJson("/api/public/{$user->slug}/reservas/{$reserva->public_token}", ['X-Device-Token' => 'device-token-de-prueba-0123456789abcdef'])
            ->assertOk()
            ->assertJsonPath('resumen.deposito', $esperado);
    }

    public function test_show_en_porcentaje_sin_total_devuelve_deposito_null(): void
    {
        $user = $this->salonPct();
        $s = $this->servicioConPrecio($user, null);
        $reserva = $this->reserva($user, [$s->id], ['estado' => 'held']);

        $this->getJson("/api/public/{$user->slug}/reservas/{$reserva->public_token}", ['X-Device-Token' => 'device-token-de-prueba-0123456789abcdef'])
            ->assertOk()
            ->assertJsonPath('resumen.deposito', null);
    }

    public function test_pago_http_sin_total_responde_422_sena_sin_total(): void
    {
        $user = $this->salonPct();
        $s = $this->servicioConPrecio($user, 0);
        $reserva = $this->reserva($user, [$s->id], ['estado' => 'held']);
        Http::fake();

        $this->postJson("/api/public/{$user->slug}/reservas/{$reserva->public_token}/pago", [], ['X-Device-Token' => 'device-token-de-prueba-0123456789abcdef'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'sena_sin_total');
        $this->assertSame(0, PagoSena::count());
    }

    // -- updatePerfil ------------------------------------------------

    private function putPerfil(User $user, array $body)
    {
        return $this->actingAs($user, 'sanctum')->putJson('/api/perfil', $body);
    }

    public function test_perfil_guarda_y_devuelve_tipo_y_porcentaje(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->putPerfil($user, ['sena_tipo' => 'porcentaje', 'sena_porcentaje' => 25.5])
            ->assertOk()
            ->assertJsonPath('sena_tipo', 'porcentaje')
            ->assertJsonPath('sena_porcentaje', 25.5);

        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/auth/me')
            ->assertJsonPath('sena_tipo', 'porcentaje')
            ->assertJsonPath('sena_porcentaje', 25.5);
    }

    public function test_perfil_rechaza_tipo_invalido_y_porcentaje_fuera_de_rango_en_modo_porcentaje(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->putPerfil($user, ['sena_tipo' => 'otro'])->assertStatus(422)->assertJsonValidationErrors('sena_tipo');
        $this->putPerfil($user, ['sena_tipo' => 'porcentaje', 'sena_porcentaje' => 0])->assertStatus(422)->assertJsonValidationErrors('sena_porcentaje');
        $this->putPerfil($user, ['sena_tipo' => 'porcentaje', 'sena_porcentaje' => 100.5])->assertStatus(422)->assertJsonValidationErrors('sena_porcentaje');
        $this->putPerfil($user, ['sena_tipo' => 'porcentaje', 'sena_porcentaje' => null])->assertStatus(422)->assertJsonValidationErrors('sena_porcentaje');
        $this->putPerfil($user, ['sena_tipo' => 'porcentaje', 'sena_porcentaje' => 100])->assertOk();
        $this->putPerfil($user, ['sena_tipo' => 'porcentaje', 'sena_porcentaje' => 1])->assertOk();
    }

    public function test_perfil_limpia_el_campo_del_modo_inactivo(): void
    {
        $user = User::factory()->create(['is_exempt' => true, 'sena_monto' => 5000]);

        $this->putPerfil($user, ['sena_tipo' => 'porcentaje', 'sena_porcentaje' => 30])
            ->assertOk()->assertJsonPath('sena_monto', null)->assertJsonPath('sena_porcentaje', 30);
        $this->putPerfil($user->fresh(), ['sena_tipo' => 'fijo', 'sena_monto' => 4000, 'sena_porcentaje' => 50])
            ->assertOk()->assertJsonPath('sena_porcentaje', null)->assertJsonPath('sena_monto', '4000.00');
    }

    public function test_perfil_con_mp_conectado_no_deja_vaciar_el_porcentaje(): void
    {
        $user = $this->salonPct();

        $this->putPerfil($user, ['sena_porcentaje' => null])->assertStatus(422)->assertJsonValidationErrors('sena_porcentaje');
    }

    public function test_perfil_con_mp_conectado_permite_pasar_a_porcentaje_con_valor_y_a_fijo_con_monto(): void
    {
        $user = $this->crearSalon(['sena_monto' => 5000]);
        UserMpCredential::create(['user_id' => $user->id, 'mp_access_token' => 'APP_USR-x', 'mp_user_id' => 'MP-1']);

        $this->putPerfil($user, ['sena_tipo' => 'porcentaje'])->assertStatus(422)->assertJsonValidationErrors('sena_porcentaje');
        $this->putPerfil($user, ['sena_tipo' => 'porcentaje', 'sena_porcentaje' => 30])->assertOk();
        // Modos excluyentes: al pasar a porcentaje se limpio sena_monto, asi
        // que volver a fijo exige cargar el monto de nuevo.
        $this->putPerfil($user->fresh(), ['sena_tipo' => 'fijo'])->assertStatus(422)->assertJsonValidationErrors('sena_monto');
        $this->putPerfil($user->fresh(), ['sena_tipo' => 'fijo', 'sena_monto' => 4000])->assertOk();
        // Fijo sin monto con MP conectado: mismo mensaje de siempre.
        $this->putPerfil($user->fresh(), ['sena_monto' => null])->assertStatus(422)->assertJsonValidationErrors('sena_monto');
    }

    public function test_pide_sena_por_whatsapp_se_rechaza_en_modo_porcentaje(): void
    {
        $user = User::factory()->create([
            'is_exempt' => true, 'direccion' => 'Calle 1', 'latitud' => -27.4, 'longitud' => -58.8,
            'sena_monto' => null,
        ]);

        // Todo completo, pero la plantilla de WhatsApp solo soporta monto fijo.
        $this->putPerfil($user, [
            'whatsapp_pide_sena' => true, 'sena_tipo' => 'porcentaje', 'sena_porcentaje' => 30,
            'whatsapp_sena_titular' => 'Ana', 'whatsapp_sena_alias' => 'ana.mp',
        ])->assertStatus(422)->assertJsonValidationErrors('whatsapp_pide_sena');
        $this->assertFalse((bool) $user->fresh()->whatsapp_pide_sena);

        // Cambiar a porcentaje con el toggle ya activo tambien se rechaza.
        $activo = User::factory()->create([
            'is_exempt' => true, 'direccion' => 'Calle 1', 'latitud' => -27.4, 'longitud' => -58.8,
            'sena_monto' => 5000, 'whatsapp_pide_sena' => true,
            'whatsapp_sena_titular' => 'Ana', 'whatsapp_sena_alias' => 'ana.mp',
        ]);
        $this->putPerfil($activo, ['sena_tipo' => 'porcentaje', 'sena_porcentaje' => 30])
            ->assertStatus(422)->assertJsonValidationErrors('whatsapp_pide_sena');

        // Apagando el toggle en el mismo request si se permite.
        $this->putPerfil($activo->fresh(), ['sena_tipo' => 'porcentaje', 'sena_porcentaje' => 30, 'whatsapp_pide_sena' => false])
            ->assertOk();
    }
}

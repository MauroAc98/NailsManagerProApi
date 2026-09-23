<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\User;
use App\Models\UserMpCredential;
use App\Services\Reservas\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class PublicReservasHoldsTest extends TestCase
{
    use CreaSalonPublico, RefreshDatabase;

    private const FECHA = '2099-06-11';

    private const DEVICE = 'device-token-de-prueba-0123456789abcdef';

    private const TEL = '+5491155551234';

    private User $user;

    private Profesional $ana;

    private Servicio $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2099-06-01 09:00:00'));
        config(['reservas.creacion_habilitada' => true, 'services.frontend_url' => 'https://app.test']);

        $this->user = $this->crearSalon(['sena_monto' => 5000]);
        $this->ana = $this->crearProfesional($this->user, 'Ana');
        $this->servicio = $this->crearServicio($this->user, 'S', 60, true, $this->ana);
        foreach (['09:00', '10:00', '11:00', '12:00', '13:00', '14:00'] as $h) {
            $this->crearSlot($this->user, $this->ana, $h);
        }

        UserMpCredential::create(['user_id' => $this->user->id, 'mp_access_token' => 'APP_USR-token-de-test', 'mp_user_id' => 'MP-1']);
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response([
            'id' => 'ORDER-TEST',
            'checkout_url' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF-TEST',
        ], 201)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(string $path = '', ?User $salon = null): string
    {
        return '/api/public/'.($salon ?? $this->user)->slug.'/reservas'.$path;
    }

    private function headers(string $device = self::DEVICE, ?string $key = 'key-1'): array
    {
        $h = ['X-Device-Token' => $device];
        if ($key !== null) {
            $h['Idempotency-Key'] = $key;
        }

        return $h;
    }

    private function body(string $hora = '10:00', ?int $profId = null, array $extra = []): array
    {
        return array_merge(['servicio_ids' => [$this->servicio->id], 'profesional_id' => $profId ?? $this->ana->id, 'fecha' => self::FECHA, 'hora' => $hora], $extra);
    }

    private function crearHold(string $hora = '10:00', string $device = self::DEVICE, string $key = 'key-1'): string
    {
        return $this->postJson($this->url('/holds'), $this->body($hora), $this->headers($device, $key))->assertSuccessful()->json('token');
    }

    private function datos(string $token, array $extra = [], string $device = self::DEVICE)
    {
        return $this->putJson($this->url("/{$token}/datos"), array_merge(['nombre' => 'Lucia', 'apellido' => 'Gomez', 'whatsapp' => self::TEL, 'nota' => 'hola'], $extra), $this->headers($device, null));
    }

    // -- 1. hold ----------------------------------------------------

    public function test_hold_devuelve_201_con_el_contrato_exacto(): void
    {
        $resp = $this->postJson($this->url('/holds'), $this->body(), $this->headers())->assertCreated();

        $resp->assertExactJson([
            'token' => $resp->json('token'),
            'estado' => 'held',
            'expira_en_ms' => (Carbon::now()->timestamp + 600) * 1000,
            'profesional_id' => $this->ana->id,
            'fecha' => self::FECHA,
            'hora' => '10:00',
            'duracion_total_minutos' => 60,
        ]);
        $this->assertSame(40, strlen($resp->json('token')));
    }

    public function test_hold_replay_con_la_misma_key_devuelve_200_y_el_mismo_token(): void
    {
        $token = $this->crearHold();

        $this->postJson($this->url('/holds'), $this->body(), $this->headers())
            ->assertOk()->assertJsonPath('token', $token)->assertJsonPath('estado', 'held');
        $this->assertSame(1, ReservaWeb::count());
    }

    public function test_hold_sin_profesional_asigna_una_libre(): void
    {
        $body = $this->body();
        unset($body['profesional_id']);

        $this->postJson($this->url('/holds'), $body, $this->headers())
            ->assertCreated()->assertJsonPath('profesional_id', $this->ana->id);
    }

    public function test_hold_sin_idempotency_key_es_422_validation(): void
    {
        $this->postJson($this->url('/holds'), $this->body(), $this->headers(self::DEVICE, null))
            ->assertStatus(422)->assertJsonPath('code', 'validation');
        $this->postJson($this->url('/holds'), $this->body(), $this->headers(self::DEVICE, str_repeat('k', 65)))
            ->assertStatus(422)->assertJsonPath('code', 'validation');
        $this->assertSame(0, ReservaWeb::count());
    }

    public function test_body_invalido_es_422_con_code_validation_y_errores(): void
    {
        $this->postJson($this->url('/holds'), ['servicio_ids' => [], 'fecha' => 'ayer', 'hora' => '25:00'], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation')
            ->assertJsonStructure(['message', 'code', 'errors' => ['servicio_ids', 'fecha', 'hora']]);
    }

    public function test_device_token_ausente_o_mal_formado_es_422_device_token_required(): void
    {
        foreach ([null, 'corto', str_repeat('a', 31), str_repeat('a', 129), str_repeat('a', 31).'!'] as $token) {
            $headers = $token === null ? ['Idempotency-Key' => 'k'] : ['X-Device-Token' => $token, 'Idempotency-Key' => 'k'];
            $this->postJson($this->url('/holds'), $this->body(), $headers)
                ->assertStatus(422)->assertJsonPath('code', 'device_token_required');
        }
        $this->assertSame(0, ReservaWeb::count());
    }

    public function test_slot_ocupado_es_409_slot_taken_y_hora_invalida_es_422(): void
    {
        $this->crearHold('10:00', self::DEVICE, 'k1');

        $this->postJson($this->url('/holds'), $this->body('10:00'), $this->headers(str_repeat('b', 40), 'k2'))
            ->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->postJson($this->url('/holds'), $this->body('10:30'), $this->headers(str_repeat('c', 40), 'k3'))
            ->assertStatus(422)->assertJsonPath('code', 'validation');
    }

    public function test_hold_de_salon_con_suscripcion_vencida_o_slug_inexistente_es_404(): void
    {
        $vencido = User::factory()->create(['is_exempt' => false]);

        $this->postJson($this->url('/holds', $vencido), $this->body(), $this->headers())->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->postJson('/api/public/no-existe/reservas/holds', $this->body(), $this->headers())->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    // -- 2. datos ---------------------------------------------------

    public function test_datos_devuelve_200_y_guarda_sin_mover_la_expiracion(): void
    {
        $token = $this->crearHold();

        $this->datos($token)->assertOk()->assertExactJson([
            'token' => $token,
            'estado' => 'held',
            'expira_en_ms' => (Carbon::now()->timestamp + 600) * 1000,
        ]);
        $this->assertSame('Lucia Gomez', ReservaWeb::first()->nombre_completo);
    }

    public function test_datos_valida_el_formato_del_whatsapp(): void
    {
        $token = $this->crearHold();

        $this->datos($token, ['whatsapp' => '11 5555 1234'])->assertStatus(422)->assertJsonPath('code', 'validation')->assertJsonStructure(['errors' => ['whatsapp']]);
        $this->datos($token, ['nombre' => ''])->assertStatus(422)->assertJsonPath('code', 'validation');
        $this->datos($token, ['nota' => str_repeat('x', 301)])->assertStatus(422);
    }

    public function test_datos_de_un_hold_vencido_es_410_hold_expired(): void
    {
        $token = $this->crearHold();
        Carbon::setTestNow(Carbon::now()->addSeconds(601));

        $this->datos($token)->assertStatus(410)->assertJsonPath('code', 'hold_expired');
    }

    public function test_g8_cooldown_de_telefono_da_429_con_retry_after_seconds(): void
    {
        $viejo = $this->crearHold('09:00', self::DEVICE, 'k1');
        $this->datos($viejo)->assertOk();
        Carbon::setTestNow(Carbon::now()->addSeconds(601));
        $this->artisan('reservas:expirar-holds')->assertSuccessful();

        $nuevo = $this->postJson($this->url('/holds'), $this->body('11:00'), $this->headers(str_repeat('d', 40), 'k2'))->assertCreated()->json('token');

        $this->datos($nuevo, [], str_repeat('d', 40))
            ->assertStatus(429)
            ->assertJsonPath('code', 'phone_cooldown')
            ->assertJsonPath('retry_after_seconds', 1800);
    }

    // -- 3. pago ----------------------------------------------------

    public function test_pago_devuelve_checkout_stub_y_es_idempotente(): void
    {
        $token = $this->crearHold();
        $this->datos($token)->assertOk();

        $resp = $this->postJson($this->url("/{$token}/pago"), [], $this->headers(self::DEVICE, null))->assertOk();
        $resp->assertExactJson([
            'token' => $token,
            'estado' => 'pending_payment',
            'expira_en_ms' => (Carbon::now()->timestamp + 900) * 1000,
            'checkout_url' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF-TEST',
        ]);

        Carbon::setTestNow(Carbon::now()->addSeconds(120));
        $this->postJson($this->url("/{$token}/pago"), [], $this->headers(self::DEVICE, null))
            ->assertOk()->assertJsonPath('expira_en_ms', $resp->json('expira_en_ms'));
    }

    public function test_pago_sin_datos_es_422_datos_required_y_vencido_es_410(): void
    {
        $token = $this->crearHold();

        $this->postJson($this->url("/{$token}/pago"), [], $this->headers(self::DEVICE, null))->assertStatus(422)->assertJsonPath('code', 'datos_required');

        $this->datos($token)->assertOk();
        Carbon::setTestNow(Carbon::now()->addSeconds(601));
        $this->postJson($this->url("/{$token}/pago"), [], $this->headers(self::DEVICE, null))->assertStatus(410)->assertJsonPath('code', 'hold_expired');
    }

    // -- 4. delete --------------------------------------------------

    public function test_delete_libera_es_idempotente_y_no_penaliza(): void
    {
        $token = $this->crearHold();

        $this->deleteJson($this->url("/{$token}"), [], $this->headers(self::DEVICE, null))->assertNoContent();
        $this->deleteJson($this->url("/{$token}"), [], $this->headers(self::DEVICE, null))->assertNoContent();

        $this->assertSame('cancelled', ReservaWeb::first()->estado);
    }

    public function test_delete_de_una_confirmada_es_409_already_confirmed(): void
    {
        $token = $this->crearHold();
        ReservaWeb::first()->update(['estado' => 'confirmed']);

        $this->deleteJson($this->url("/{$token}"), [], $this->headers(self::DEVICE, null))->assertStatus(409)->assertJsonPath('code', 'already_confirmed');
    }

    // -- 5. estado --------------------------------------------------

    // deposito ya NO es sena_monto crudo: es lo que se le cobra a la
    // clienta para que, descontada la comision de MP, el negocio reciba los
    // 5000 completos (ver MercadoPagoService::montoACobrar).
    public function test_estado_devuelve_el_contrato_con_resumen(): void
    {
        $this->user->update(['sena_monto' => 5000]);
        $token = $this->crearHold();
        $this->datos($token)->assertOk();
        $depositoEsperado = app(MercadoPagoService::class)->montoACobrar(5000);

        $this->getJson($this->url("/{$token}"), $this->headers(self::DEVICE, null))->assertOk()->assertExactJson([
            'token' => $token,
            'estado' => 'held',
            'expira_en_ms' => (Carbon::now()->timestamp + 600) * 1000,
            'resumen' => [
                'servicio_ids' => [$this->servicio->id],
                'profesional_id' => $this->ana->id,
                'fecha' => self::FECHA,
                'hora' => '10:00',
                'duracion_total_minutos' => 60,
                'deposito' => $depositoEsperado,
                'nota' => 'hola',
            ],
        ]);
    }

    public function test_estado_pending_payment_incluye_checkout_url_y_los_estados_legacy_se_mapean(): void
    {
        $token = $this->crearHold();
        $this->datos($token);
        $this->postJson($this->url("/{$token}/pago"), [], $this->headers(self::DEVICE, null));

        $this->getJson($this->url("/{$token}"), $this->headers(self::DEVICE, null))
            ->assertOk()->assertJsonPath('estado', 'pending_payment')->assertJsonPath('checkout_url', 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF-TEST');

        ReservaWeb::first()->update(['estado' => 'accepted']);
        $this->getJson($this->url("/{$token}"), $this->headers(self::DEVICE, null))->assertJsonPath('estado', 'confirmed');
        ReservaWeb::first()->update(['estado' => 'rejected']);
        $this->getJson($this->url("/{$token}"), $this->headers(self::DEVICE, null))->assertJsonPath('estado', 'cancelled');
    }

    public function test_estado_de_un_hold_vencido_se_lee_como_expired_con_200(): void
    {
        $token = $this->crearHold();
        Carbon::setTestNow(Carbon::now()->addSeconds(700));

        $this->getJson($this->url("/{$token}"), $this->headers(self::DEVICE, null))->assertOk()->assertJsonPath('estado', 'expired');
    }

    public function test_g15_token_desconocido_o_de_otro_salon_es_404_y_los_ids_no_se_aceptan(): void
    {
        $token = $this->crearHold();
        $otro = $this->crearSalon();
        $id = ReservaWeb::first()->id;

        $this->getJson($this->url("/{$token}", $otro), $this->headers(self::DEVICE, null))->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->getJson($this->url('/'.str_repeat('z', 40)), $this->headers(self::DEVICE, null))->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->getJson($this->url("/{$id}"), $this->headers(self::DEVICE, null))->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    // -- kill switch / throttles / challenge / logs -------------------

    public function test_g10_con_el_flag_apagado_las_5_escrituras_dan_503_creation_disabled(): void
    {
        $token = $this->crearHold();
        config(['reservas.creacion_habilitada' => false]);
        $h = $this->headers(self::DEVICE, 'kx');

        foreach ([
            $this->postJson($this->url('/holds'), $this->body(), $h),
            $this->putJson($this->url("/{$token}/datos"), [], $h),
            $this->postJson($this->url("/{$token}/pago"), [], $h),
            $this->deleteJson($this->url("/{$token}"), [], $h),
            $this->getJson($this->url("/{$token}"), $h),
            $this->postJson($this->url('/holds'), $this->body(), []), // ni siquiera pide device token
        ] as $resp) {
            $resp->assertStatus(503)->assertExactJson(['message' => 'La reserva online todavía no está disponible.', 'code' => 'creation_disabled']);
        }

        // Las lecturas de disponibilidad no dependen del flag.
        $this->getJson("/api/public/{$this->user->slug}/disponibilidad?fecha=".self::FECHA."&servicio_ids[]={$this->servicio->id}")->assertOk();
    }

    public function test_la_ruta_vieja_post_reservas_ya_no_existe(): void
    {
        $this->postJson($this->url(), ['nombre_completo' => 'X', 'telefono' => self::TEL, 'servicio_ids' => [$this->servicio->id], 'fecha' => self::FECHA, 'slot_hora' => '10:00'])
            ->assertNotFound();
        $this->assertSame(0, ReservaWeb::count());
    }

    public function test_el_flag_esta_apagado_por_defecto(): void
    {
        $this->assertFalse((bool) (include base_path('config/reservas.php'))['creacion_habilitada']);
    }

    public function test_hold_throttle_por_dispositivo_3_por_minuto_y_no_por_ip(): void
    {
        $slots = ['09:00', '10:00', '11:00', '12:00'];
        foreach (['k1', 'k2', 'k3'] as $i => $key) {
            $this->assertNotSame(429, $this->postJson($this->url('/holds'), $this->body($slots[$i]), $this->headers(self::DEVICE, $key))->getStatusCode());
        }

        $this->postJson($this->url('/holds'), $this->body('12:00'), $this->headers(self::DEVICE, 'k4'))
            ->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertJsonStructure(['message', 'code', 'retry_after_seconds']);

        // Misma IP, otros dispositivos: no se ven afectados.
        foreach (range(1, 4) as $n) {
            $this->assertNotSame(429, $this->postJson($this->url('/holds'), $this->body('12:00'), $this->headers(str_repeat((string) $n, 40), "kk{$n}"))->getStatusCode());
        }
    }

    public function test_estado_throttle_60_por_minuto_por_token(): void
    {
        $token = $this->crearHold();
        $otro = $this->crearHold('12:00', str_repeat('e', 40), 'ke');

        for ($i = 0; $i < 60; $i++) {
            $this->assertNotSame(429, $this->getJson($this->url("/{$token}"), $this->headers(self::DEVICE, null))->getStatusCode());
        }
        $this->getJson($this->url("/{$token}"), $this->headers(self::DEVICE, null))->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        $this->getJson($this->url("/{$otro}"), $this->headers(str_repeat('e', 40), null))->assertOk();
    }

    public function test_datos_throttle_5_por_hora_por_telefono(): void
    {
        $tokens = [];
        foreach (range(1, 6) as $n) {
            $device = str_repeat((string) $n, 40);
            $tokens[$n] = $this->postJson($this->url('/holds'), $this->body(['09:00', '10:00', '11:00', '12:00', '13:00', '14:00'][$n - 1]), $this->headers($device, "k{$n}"))->json('token');
        }
        $ok = 0;
        foreach (range(1, 5) as $n) {
            $codigo = $this->putJson($this->url("/{$tokens[$n]}/datos"), ['nombre' => 'A', 'apellido' => 'B', 'whatsapp' => self::TEL], $this->headers(str_repeat((string) $n, 40), null))->getStatusCode();
            $this->assertNotSame(429, $codigo, "n={$n}");
            $ok++;
        }
        $this->assertSame(5, $ok);
        $this->putJson($this->url("/{$tokens[6]}/datos"), ['nombre' => 'A', 'apellido' => 'B', 'whatsapp' => '+54 9 11 5555-1234'], $this->headers(str_repeat('6', 40), null))
            ->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }

    public function test_g9_con_turnstile_habilitado_y_reto_invalido_da_403_y_no_crea_fila(): void
    {
        config(['reservas.challenge.habilitado' => true, 'services.turnstile.secret' => 's']);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);

        $this->postJson($this->url('/holds'), $this->body('10:00', null, ['challenge_token' => 'malo']), $this->headers())
            ->assertStatus(403)->assertJsonPath('code', 'challenge_failed');
        $this->assertSame(0, ReservaWeb::count());

        $this->postJson($this->url('/holds'), $this->body('10:00', null, ['challenge_token' => 'bueno']), $this->headers(self::DEVICE, 'k2'))->assertCreated();
    }

    public function test_los_logs_no_contienen_pii_ni_el_token_del_dispositivo(): void
    {
        $lineas = [];
        Log::listen(function ($m) use (&$lineas) {
            $lineas[] = $m->message.' '.json_encode($m->context);
        });

        $token = $this->crearHold();
        $this->datos($token, ['nombre' => 'Lucia', 'apellido' => 'Gomez', 'nota' => 'nota-secreta'])->assertOk();
        $this->postJson($this->url("/{$token}/pago"), [], $this->headers(self::DEVICE, null))->assertOk();
        $this->deleteJson($this->url("/{$token}"), [], $this->headers(self::DEVICE, null));

        $todo = implode("\n", $lineas);
        $this->assertStringContainsString('reserva.hold.created', $todo);
        foreach (['Lucia', 'Gomez', 'nota-secreta', self::TEL, '5491155551234', self::DEVICE, $token] as $secreto) {
            $this->assertStringNotContainsString($secreto, $todo, "el log filtra: {$secreto}");
        }
    }
}

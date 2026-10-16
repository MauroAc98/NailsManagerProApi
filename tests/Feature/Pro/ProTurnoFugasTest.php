<?php

namespace Tests\Feature\Pro;

use App\Models\PagoSena;
use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
use App\Models\ReservaWeb;
use App\Models\Turno;
use App\Models\TurnoGrupo;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * Data-exposure contract of the professional's agenda (AG-05, AG-06, NF-PRIV-1).
 *
 * AssertsContractShape only pins types and tolerates extra keys, so this file
 * proves the two things it cannot:
 *  1. the exact key set at every nesting level (an unknown key is a leak);
 *  2. canary values planted in every sensitive column or table (client phone,
 *     prices, deposit, public token, owner email, WhatsApp, other professional)
 *     never appear anywhere in the raw response body.
 * Both run against the list AND the detail endpoint.
 */
class ProTurnoFugasTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private const CLAVES_ITEM = ['id', 'fecha_hora', 'duracion_total_minutos', 'estado_visual', 'notas', 'cliente', 'servicios', 'promo'];

    /** Keys that must not exist at any depth of any response of this feature. */
    private const CLAVES_PROHIBIDAS = [
        'telefono', 'whatsapp_opt_out', 'user_id', 'cliente_id', 'profesional_id', 'precio', 'precio_sugerido',
        'pivot', 'sena', 'pago_sena', 'reserva_web', 'reserva_web_id', 'public_token', 'origen', 'motivo_cancelacion',
        'cancelado_en', 'estado', 'grupo_id', 'lote_id', 'tramos', 'grupo', 'precio_promo', 'modo', 'promo_servicio_id',
        'created_at', 'updated_at', 'email', 'confirmacion_whatsapp_status', 'whatsapp_ultimo_status', 'duracion_minutos',
    ];

    /** Distinctive values seeded in the sensitive fields. */
    private const CANARIOS = [
        'telefono del cliente' => '+5491199887766',
        'telefono sin formato' => '1199887766',
        'precio de lista del servicio' => '54321',
        'precio del pivot' => '98765',
        'precio sugerido del pivot' => '87654',
        'monto de la sena' => '31415',
        'preference de mercado pago' => 'pref-canario-zx81',
        'token publico de la reserva' => 'tok-canario-publico-q7',
        'email de la persona titular' => 'titular-canario@leak.test',
        'nombre de la persona titular' => 'TitularCanarioNombre',
        'texto del mensaje de whatsapp' => 'mensaje-whatsapp-canario',
        'numero de whatsapp' => '5493765009999',
        'status de whatsapp' => 'status-canario-wa',
        'precio de la promo' => '77777',
        'lote' => 'lote-canario-k3',
        'motivo de cancelacion' => 'motivo-canario-m9',
        'profesional ajena' => 'ProfesionalAjenaCanario',
        'servicio de la profesional ajena' => 'ServicioAjenoCanario',
        'notas de la profesional ajena' => 'notas-ajenas-canario',
        'cliente de la profesional ajena' => 'ClienteAjenoCanario',
        'hora de la profesional ajena' => '15:45',
    ];

    private array $negocio;

    private ProfesionalCuenta $cuenta;

    private ProfesionalVinculo $vinculo;

    private string $token;

    /** @var array{suyo: Turno, deLaAjena: Turno, soloSuyo: Turno} */
    private array $turnos;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 12:00:00');
        $this->negocio = $this->crearNegocioPortal(['name' => 'TitularCanarioNombre', 'email' => 'titular-canario@leak.test']);
        $this->negocio['bea']->forceFill(['nombre' => 'ProfesionalAjenaCanario'])->save();
        $this->cuenta = $this->crearCuentaPortal();
        $this->vinculo = $this->vincularPortal($this->cuenta, $this->negocio['ana']);
        $this->token = $this->tokenPara($this->cuenta);
        $this->turnos = $this->sembrar();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Her turno is the worst case: web booking with a paid deposit, WhatsApp
     * messages, a promo group shared with another professional, prices on the
     * service and on the pivot. A second turno of hers has none of that.
     *
     * @return array{suyo: Turno, deLaAjena: Turno, soloSuyo: Turno}
     */
    private function sembrar(): array
    {
        $user = $this->negocio['user'];
        $cliente = $this->crearClientePortal($user, 'Maria', 'Lopez', '+5491199887766');
        $cliente->forceFill(['whatsapp_opt_out' => true])->save();
        $clienteAjeno = $this->crearClientePortal($user, 'ClienteAjenoCanario', 'Otra', '+5491100000002');

        $capping = $this->crearServicioPortal($user, 'Capping', ['precio' => 54321]);
        $esmaltado = $this->crearServicioPortal($user, 'Esmaltado', ['precio' => 54322]);
        $ajeno = $this->crearServicioPortal($user, 'ServicioAjenoCanario', ['precio' => 11223]);
        $promo = $this->crearServicioPortal($user, 'Manicura + Pedicura', ['es_promo' => true, 'precio' => 77777]);

        $reserva = ReservaWeb::create([
            'user_id' => $user->id,
            'profesional_id' => $this->negocio['ana']->id,
            'public_token' => 'tok-canario-publico-q7',
            'servicio_ids' => [$capping->id],
            'estado' => 'confirmed',
            'fecha' => '2026-10-17',
            'slot_hora' => '10:00:00',
            'duracion_total_minutos' => 60,
            'nombre_completo' => 'Maria Lopez',
            'telefono' => '+5491199887766',
        ]);
        PagoSena::create(['reserva_web_id' => $reserva->id, 'mp_preference_id' => 'pref-canario-zx81', 'monto' => 31415, 'estado' => 'aprobado']);

        $grupo = TurnoGrupo::create(['modo' => 'secuencia', 'promo_servicio_id' => $promo->id, 'precio_promo' => 77777, 'reserva_web_id' => $reserva->id]);

        $suyo = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 10:00:00', [
            'cliente_id' => $cliente->id,
            'reserva_web_id' => $reserva->id,
            'grupo_id' => $grupo->id,
            'lote_id' => 'lote-canario-k3',
            'origen' => 'web',
            'motivo_cancelacion' => 'motivo-canario-m9',
            'notas' => 'Quiere diseño simple',
        ]);
        $suyo->servicios()->attach($capping->id, ['precio' => 98765, 'precio_sugerido' => 87654]);
        $suyo->servicios()->attach($esmaltado->id, ['precio' => 98765, 'precio_sugerido' => 87654]);

        $deLaAjena = $this->crearTurnoPortal($this->negocio['bea'], '2026-10-17 15:45:00', [
            'cliente_id' => $clienteAjeno->id,
            'reserva_web_id' => $reserva->id,
            'grupo_id' => $grupo->id,
            'notas' => 'notas-ajenas-canario',
        ]);
        $deLaAjena->servicios()->attach($ajeno->id, ['precio' => 11223]);

        foreach ([$suyo, $deLaAjena] as $turno) {
            WhatsappMensaje::create([
                'user_id' => $user->id,
                'turno_id' => $turno->id,
                'numero' => '5493765009999',
                'provider' => 'cloud_api',
                'mensaje' => 'mensaje-whatsapp-canario',
                'tipo' => 'confirmacion',
                'message_id' => 'wamid.canario',
                'status' => 'status-canario-wa',
            ]);
        }

        $soloSuyo = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 11:30:00');

        return ['suyo' => $suyo, 'deLaAjena' => $deLaAjena, 'soloSuyo' => $soloSuyo];
    }

    private function llamar(string $uri): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->getJson($uri, ['X-Vinculo-Id' => (string) $this->vinculo->id]);
    }

    /** @return list<string> every key at every depth, including list indexes ignored. */
    private function claves(mixed $valor): array
    {
        $claves = [];
        if (is_array($valor)) {
            foreach ($valor as $clave => $hijo) {
                if (is_string($clave)) {
                    $claves[] = $clave;
                }
                $claves = array_merge($claves, $this->claves($hijo));
            }
        }

        return $claves;
    }

    private function assertItemSeguro(array $item): void
    {
        $this->assertEqualsCanonicalizing(self::CLAVES_ITEM, array_keys($item));
        $this->assertEqualsCanonicalizing(['nombre', 'apellido'], array_keys($item['cliente']));
        foreach ($item['servicios'] as $servicio) {
            $this->assertEqualsCanonicalizing(['id', 'nombre'], array_keys($servicio));
        }
        if ($item['promo'] !== null) {
            $this->assertEqualsCanonicalizing(['nombre'], array_keys($item['promo']));
        }
    }

    private function assertSinCanarios(string $cuerpo): void
    {
        foreach (self::CANARIOS as $descripcion => $canario) {
            $this->assertStringNotContainsString($canario, $cuerpo, "leak: {$descripcion}");
        }
    }

    private function assertSinClavesProhibidas(array $json): void
    {
        $this->assertSame([], array_values(array_intersect(self::CLAVES_PROHIBIDAS, $this->claves($json))));
        foreach ($this->claves($json) as $clave) {
            $this->assertStringStartsNotWith('whatsapp', $clave);
        }
    }

    // ── list ─────────────────────────────────────────────────────

    public function test_the_list_exposes_exact_keys_at_every_level(): void
    {
        $r = $this->llamar('/api/pro/turnos')->assertOk();

        $this->assertEqualsCanonicalizing(['data', 'truncated'], array_keys($r->json()));
        $this->assertSame([$this->turnos['suyo']->id, $this->turnos['soloSuyo']->id], array_column($r->json('data'), 'id'));
        // The nested checks must have something to look at: her worst-case turno has services and a promo.
        $this->assertCount(2, $r->json('data.0.servicios'));
        $this->assertNotNull($r->json('data.0.promo'));
        foreach ($r->json('data') as $item) {
            $this->assertItemSeguro($item);
        }
    }

    public function test_the_list_body_contains_no_canary_value_and_no_forbidden_key(): void
    {
        $r = $this->llamar('/api/pro/turnos')->assertOk();

        $this->assertSinCanarios($r->getContent());
        $this->assertSinClavesProhibidas($r->json());
        // Personal data about clients must not be cached by a shared device or proxy.
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
    }

    public function test_the_list_still_shows_the_data_a_professional_is_meant_to_see(): void
    {
        $item = $this->llamar('/api/pro/turnos')->assertOk()->json('data.0');

        $this->assertSame('Quiere diseño simple', $item['notas']);
        $this->assertSame(['nombre' => 'Maria', 'apellido' => 'Lopez'], $item['cliente']);
        $this->assertSame(['Capping', 'Esmaltado'], array_column($item['servicios'], 'nombre'));
        $this->assertSame(['nombre' => 'Manicura + Pedicura'], $item['promo']);
    }

    public function test_a_turno_without_client_reserva_or_promo_keeps_the_same_shape(): void
    {
        $item = $this->llamar('/api/pro/turnos')->assertOk()->json('data.1');

        $this->assertSame($this->turnos['soloSuyo']->id, $item['id']);
        $this->assertEqualsCanonicalizing(self::CLAVES_ITEM, array_keys($item));
        $this->assertSame(['nombre' => null, 'apellido' => null], $item['cliente']);
        $this->assertSame([], $item['servicios']);
        $this->assertNull($item['promo']);
        $this->assertNull($item['notas']);
    }

    // ── detail ───────────────────────────────────────────────────

    public function test_the_detail_exposes_exact_keys_at_every_level(): void
    {
        $r = $this->llamar('/api/pro/turnos/'.$this->turnos['suyo']->id)->assertOk();

        $this->assertCount(2, $r->json('servicios'));
        $this->assertNotNull($r->json('promo'));
        $this->assertItemSeguro($r->json());
        $this->assertSame($this->turnos['suyo']->id, $r->json('id'));
    }

    public function test_the_detail_body_contains_no_canary_value_and_no_forbidden_key(): void
    {
        $r = $this->llamar('/api/pro/turnos/'.$this->turnos['suyo']->id)->assertOk();

        $this->assertSinCanarios($r->getContent());
        $this->assertSinClavesProhibidas($r->json());
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
    }

    public function test_the_other_professionals_step_of_the_shared_promo_is_not_reachable(): void
    {
        $r = $this->llamar('/api/pro/turnos/'.$this->turnos['deLaAjena']->id)->assertStatus(404);

        $this->assertSinCanarios($r->getContent());
        $this->assertSame(['message', 'code'], array_keys($r->json()));
    }

    // ── the guard rails of the fixture itself ────────────────────

    public function test_the_canaries_really_exist_in_the_database(): void
    {
        // If a canary were not seeded the "absent" assertions above would be vacuous.
        $this->assertSame('+5491199887766', $this->turnos['suyo']->cliente->telefono);
        $this->assertSame('tok-canario-publico-q7', $this->turnos['suyo']->reservaWeb->public_token);
        $this->assertSame('31415.00', (string) $this->turnos['suyo']->reservaWeb->pagoSena->monto);
        $this->assertSame(98765.0, (float) $this->turnos['suyo']->servicios->first()->pivot->precio);
        $this->assertSame('54321.00', (string) $this->turnos['suyo']->servicios->first()->precio);
        $this->assertSame('titular-canario@leak.test', $this->negocio['user']->email);
        $this->assertSame('ProfesionalAjenaCanario', $this->turnos['deLaAjena']->profesional->nombre);
        $this->assertSame(2, WhatsappMensaje::count());
        $this->assertSame('lote-canario-k3', $this->turnos['suyo']->lote_id);
        $this->assertSame('motivo-canario-m9', $this->turnos['suyo']->motivo_cancelacion);
    }
}

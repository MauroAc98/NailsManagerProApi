<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\TurnoGrupo;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappTemplate;
use App\Services\CloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * combo-multi-profesional, PR 6a: UN mensaje de WhatsApp por grupo (confirmacion
 * y recordatorio), con {{7}} = "Ana y Laura" y {{5}} = servicios de todos los
 * tramos vigentes. Un turno sin grupo manda exactamente lo de siempre (Rule L).
 * Nada sale a la red: Http::fake().
 */
class WhatsappGrupoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Cliente $cliente;

    private Servicio $softgel;

    private Servicio $semis;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(CloudApiService::CACHE_KEY_SALUD);
        $this->user = User::factory()->create([
            'is_exempt' => true, 'telefono' => '+543765111111', 'confirmacion_automatica' => true,
            'recordatorio_automatico' => true, 'hora_recordatorio' => now()->format('H:00'),
        ]);
        $this->cliente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Marta', 'apellido' => 'Rios', 'telefono' => '+543765252395']);
        $this->softgel = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Softgel', 'duracion_minutos' => 60, 'precio' => 5000, 'activo' => true]);
        $this->semis = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Semis', 'duracion_minutos' => 45, 'precio' => 4000, 'activo' => true]);
    }

    private function profesional(string $nombre): Profesional
    {
        return Profesional::create(['user_id' => $this->user->id, 'nombre' => $nombre, 'activo' => true]);
    }

    private function turno(Profesional $p, Servicio $s, $cuando, ?int $grupoId = null, string $estado = 'confirmado'): Turno
    {
        $t = Turno::create([
            'user_id' => $this->user->id, 'cliente_id' => $this->cliente->id, 'profesional_id' => $p->id, 'grupo_id' => $grupoId,
            'fecha_hora' => $cuando, 'duracion_total_minutos' => $s->duracion_minutos, 'estado' => $estado, 'origen' => 'app',
        ]);
        $t->servicios()->attach($s->id);

        return $t;
    }

    /** @return array{0: Turno, 1: Turno} */
    private function grupo($base): array
    {
        $g = TurnoGrupo::create(['modo' => 'secuencia']);

        return [
            $this->turno($this->profesional('Ana María'), $this->softgel, $base, $g->id),
            $this->turno($this->profesional('Laura'), $this->semis, $base->copy()->addHour(), $g->id),
        ];
    }

    private function parametros(Turno $t, string $tipo = 'confirmacion'): array
    {
        return WhatsappTemplate::parametrosCloudApi($tipo, $this->cliente, $t->fresh(['servicios', 'profesional']), $this->user);
    }

    public function test_un_turno_sin_grupo_conserva_sus_parametros(): void
    {
        $t = $this->turno($this->profesional('Fernanda Lopez'), $this->softgel, now()->addDay()->setTime(10, 0));

        $p = $this->parametros($t);

        $this->assertSame('Softgel', $p[4]);
        $this->assertSame('Fernanda', $p[6]);
    }

    public function test_un_grupo_lista_a_las_profesionales_y_los_servicios_de_todos_los_tramos(): void
    {
        [$a] = $this->grupo(now()->addDay()->setTime(10, 0));

        $p = $this->parametros($a);

        $this->assertSame('Softgel + Semis', $p[4]);
        $this->assertSame('Ana y Laura', $p[6]);
        $this->assertSame('10:00', $p[3]);
    }

    public function test_los_tramos_cancelados_no_figuran_en_el_mensaje(): void
    {
        [$a, $b] = $this->grupo(now()->addDay()->setTime(10, 0));
        $b->update(['estado' => 'cancelado']);

        $p = $this->parametros($a);

        $this->assertSame('Softgel', $p[4]);
        $this->assertSame('Ana', $p[6]);
    }

    public function test_tres_profesionales_se_unen_con_comas_y_una_y(): void
    {
        [$a] = $this->grupo(now()->addDay()->setTime(10, 0));
        $this->turno($this->profesional('Sol'), $this->semis, now()->addDay()->setTime(12, 0), $a->grupo_id);

        $this->assertSame('Ana, Laura y Sol', $this->parametros($a)[6]);
    }

    public function test_la_confirmacion_de_un_grupo_sale_una_sola_vez_aunque_se_dispare_para_cada_tramo(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.G1']]], 200)]);
        [$a, $b] = $this->grupo(now()->addHours(3));

        (new EnviarMensajeConfirmacion($a->id))->handle(app(CloudApiService::class));
        (new EnviarMensajeConfirmacion($b->id))->handle(app(CloudApiService::class));

        $this->assertSame(1, WhatsappMensaje::where('tipo', 'confirmacion')->count());
        Http::assertSentCount(1);
    }

    public function test_el_recordatorio_de_un_grupo_sale_una_sola_vez(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.R1']]], 200)]);
        $this->grupo(now()->addDay()->setTime(10, 0));

        $this->artisan('recordatorios:enviar');

        $this->assertSame(1, WhatsappMensaje::where('tipo', 'recordatorio')->count());
        Http::assertSentCount(1);
    }

    public function test_si_un_tramo_ya_tiene_recordatorio_el_grupo_no_vuelve_a_avisar(): void
    {
        Http::fake();
        [$a] = $this->grupo(now()->addDay()->setTime(10, 0));
        WhatsappMensaje::create(['user_id' => $this->user->id, 'turno_id' => $a->id, 'numero' => 'x', 'mensaje' => '', 'tipo' => 'recordatorio', 'status' => 'manual']);

        $this->artisan('recordatorios:enviar');

        Http::assertNothingSent();
        $this->assertSame(1, WhatsappMensaje::where('tipo', 'recordatorio')->count());
    }

    public function test_dos_turnos_comunes_el_mismo_dia_siguen_recibiendo_un_recordatorio_cada_uno(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.R2']]], 200)]);
        $p = $this->profesional('Ana');
        $this->turno($p, $this->softgel, now()->addDay()->setTime(10, 0));
        $this->turno($p, $this->semis, now()->addDay()->setTime(12, 0));

        $this->artisan('recordatorios:enviar');

        $this->assertSame(2, WhatsappMensaje::where('tipo', 'recordatorio')->count());
    }
}

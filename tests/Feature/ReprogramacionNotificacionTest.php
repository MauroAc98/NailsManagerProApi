<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Profesional;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\TurnoGrupo;
use App\Models\WhatsappMensaje;
use App\Services\CloudApiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Contract\AdminContractTestCase;

/**
 * combo-multi-profesional, PR 6f: tras reprogramar un grupo sale UN WhatsApp a
 * la clienta (plantilla `confirmacion`, registrado como tipo='reprogramacion',
 * con las mismas guardas que una confirmacion). La respuesta informa
 * `notificacion` = 'enviada' | 'omitida' (+ `motivo`). Nada sale a la red.
 */
class ReprogramacionNotificacionTest extends AdminContractTestCase
{
    private Profesional $laura;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(CloudApiService::CACHE_KEY_SALUD);
        $this->user->update(['confirmacion_automatica' => true, 'telefono' => '+543765111111']);
        $this->cliente->update(['telefono' => '+543765252395']);
        $this->laura = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Laura', 'activo' => true]);
        foreach (['09:00:00', '18:00:00'] as $hora) {
            SlotDisponible::create(['user_id' => $this->user->id, 'profesional_id' => $this->laura->id, 'hora' => $hora, 'activo' => true]);
        }
    }

    /** @return array{0: Turno, 1: Turno} Ana 10:00 y Laura 11:00 */
    private function grupo(string $dia = '2099-06-10'): array
    {
        $g = TurnoGrupo::create(['modo' => 'secuencia']);
        $a = $this->crearTurno('confirmado', "{$dia} 10:00:00");
        $b = $this->crearTurno('confirmado', "{$dia} 11:00:00");
        $a->update(['grupo_id' => $g->id]);
        $b->update(['grupo_id' => $g->id, 'profesional_id' => $this->laura->id]);

        return [$a, $b];
    }

    private function reprogramar(Turno $t, string $fechaHora = '2099-06-12 14:00:00')
    {
        return $this->admin()->postJson("/api/turnos/grupos/{$t->grupo_id}/reprogramar", ['fecha_hora' => $fechaHora]);
    }

    private function correr(int $turnoId): void
    {
        (new EnviarMensajeConfirmacion($turnoId, 'reprogramacion'))->handle(app(CloudApiService::class));
    }

    public function test_encola_un_solo_aviso_para_el_grupo_con_el_tramo_que_empieza_primero(): void
    {
        [$a] = $this->grupo();

        $this->reprogramar($a)->assertOk()->assertJsonPath('notificacion', 'enviada')->assertJsonMissingPath('motivo');

        Queue::assertPushed(EnviarMensajeConfirmacion::class, 1);
        Queue::assertPushed(EnviarMensajeConfirmacion::class, fn ($j) => $j->tipo === 'reprogramacion' && $j->turnoId === $a->id);
    }

    private function assertOmitida(string $motivo): void
    {
        [$a] = $this->grupo();

        $this->reprogramar($a)->assertOk()->assertJsonPath('notificacion', 'omitida')->assertJsonPath('motivo', $motivo);

        Queue::assertNothingPushed();
    }

    public function test_omite_el_aviso_con_la_confirmacion_automatica_apagada(): void
    {
        $this->user->update(['confirmacion_automatica' => false]);
        $this->assertOmitida('confirmacion_automatica_apagada');
    }

    public function test_omite_el_aviso_si_la_clienta_dio_de_baja_los_mensajes(): void
    {
        $this->cliente->update(['whatsapp_opt_out' => true]);
        $this->assertOmitida('opt_out');
    }

    public function test_omite_el_aviso_con_un_telefono_invalido(): void
    {
        $this->cliente->update(['telefono' => '123']);
        $this->assertOmitida('telefono_invalido');
    }

    public function test_omite_el_aviso_si_la_cuenta_manda_los_mensajes_a_mano(): void
    {
        $this->user->update(['direccion' => '']); // sin direccion la cuenta pasa a envio manual
        $this->assertOmitida('envio_manual');
    }

    public function test_el_job_manda_un_mensaje_con_ambas_profesionales_aunque_ya_haya_una_confirmacion(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.RP1']]], 200)]);
        [$a, $b] = $this->grupo();
        WhatsappMensaje::create(['user_id' => $this->user->id, 'turno_id' => $a->id, 'numero' => 'x', 'mensaje' => '', 'tipo' => 'confirmacion', 'status' => 'delivered']);

        $this->correr($a->id);
        $this->correr($b->id); // el otro tramo no duplica

        $m = WhatsappMensaje::where('tipo', 'reprogramacion')->get();
        $this->assertCount(1, $m);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data()), 'Ana y Laura'));
    }

    public function test_una_segunda_reprogramacion_vuelve_a_avisar_reemplazando_el_registro_anterior(): void
    {
        [$a] = $this->grupo();
        WhatsappMensaje::create(['user_id' => $this->user->id, 'turno_id' => $a->id, 'numero' => 'x', 'mensaje' => '', 'tipo' => 'reprogramacion', 'status' => 'delivered']);

        $this->reprogramar($a)->assertOk()->assertJsonPath('notificacion', 'enviada');

        $this->assertSame(0, WhatsappMensaje::where('tipo', 'reprogramacion')->count());
        Queue::assertPushed(EnviarMensajeConfirmacion::class, 1);
    }

    public function test_una_confirmacion_comun_sigue_registrandose_como_confirmacion(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.C1']]], 200)]);
        $t = $this->crearTurno('confirmado', now()->addDay()->format('Y-m-d').' 10:00:00');

        (new EnviarMensajeConfirmacion($t->id))->handle(app(CloudApiService::class));

        $this->assertSame(1, WhatsappMensaje::where('turno_id', $t->id)->where('tipo', 'confirmacion')->count());
        $this->assertSame('confirmacion', (new EnviarMensajeConfirmacion($t->id))->tipo);
    }

    public function test_despues_de_reprogramar_el_comando_manda_un_recordatorio_para_el_horario_nuevo(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.REC']]], 200)]);
        $this->user->update(['recordatorio_automatico' => true, 'hora_recordatorio' => now()->format('H:00')]);
        [$a, $b] = $this->grupo();
        WhatsappMensaje::create(['user_id' => $this->user->id, 'turno_id' => $b->id, 'numero' => 'x', 'mensaje' => '', 'tipo' => 'recordatorio', 'status' => 'manual']);

        $this->reprogramar($a, Carbon::tomorrow()->format('Y-m-d').' 10:00:00')->assertOk();
        $this->artisan('recordatorios:enviar');

        $this->assertSame(1, WhatsappMensaje::where('tipo', 'recordatorio')->count());
        Http::assertSentCount(1);
    }
}

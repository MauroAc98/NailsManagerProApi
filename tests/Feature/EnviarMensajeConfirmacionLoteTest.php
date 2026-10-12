<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\Subscription;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Services\CloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One combined WhatsApp per batch (lote) of turnos: the job picks the lote
 * template when 2+ non-cancelled turnos remain, dedups per lote and falls back
 * to one individual message per turno if the combined text is too long.
 * Nothing goes out to the network: Http::fake().
 */
class EnviarMensajeConfirmacionLoteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Cliente $cliente;

    private Servicio $mani;

    private Profesional $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(CloudApiService::CACHE_KEY_SALUD);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.L1']]], 200)]);
        $this->user = $this->salon();
        $this->cliente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Marta', 'apellido' => 'Rios', 'telefono' => '+543765252395']);
        $this->mani = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Manicura', 'duracion_minutos' => 30, 'precio' => 1000, 'activo' => true]);
        $this->ana = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'activo' => true]);
    }

    private function salon(array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'is_exempt' => true, 'telefono' => '+543765111111', 'confirmacion_automatica' => true,
        ], $extra));
    }

    private function conSena(array $extra = []): void
    {
        $this->user->update(array_merge([
            'whatsapp_pide_sena' => true, 'sena_monto' => 2500,
            'whatsapp_sena_titular' => 'Kim', 'whatsapp_sena_alias' => 'kim.alias',
        ], $extra));
    }

    private function turno(string $cuando, ?string $loteId, ?Servicio $servicio = null, ?Profesional $prof = null): Turno
    {
        $t = Turno::create([
            'user_id' => $this->user->id, 'cliente_id' => $this->cliente->id, 'profesional_id' => ($prof ?? $this->ana)->id,
            'lote_id' => $loteId, 'fecha_hora' => $cuando, 'duracion_total_minutos' => 30, 'estado' => 'confirmado', 'origen' => 'app',
        ]);
        $t->servicios()->attach(($servicio ?? $this->mani)->id);

        return $t;
    }

    /** @return array{0: Turno, 1: Turno} */
    private function lote(?Profesional $segunda = null): array
    {
        $id = (string) Str::uuid();

        return [
            $this->turno(now()->addDays(2)->setTime(15, 30)->format('Y-m-d H:i:s'), $id),
            $this->turno(now()->addDays(4)->setTime(10, 0)->format('Y-m-d H:i:s'), $id, null, $segunda),
        ];
    }

    private function correr(Turno $t, string $tipo = 'confirmacion'): void
    {
        (new EnviarMensajeConfirmacion($t->id, $tipo))->handle(app(CloudApiService::class));
    }

    private function enviado(): array
    {
        $enviados = Http::recorded();
        $this->assertCount(1, $enviados);

        return $enviados->first()[0]->data()['template'];
    }

    public function test_un_lote_manda_un_solo_mensaje_con_la_plantilla_de_lote(): void
    {
        [$a] = $this->lote();

        $this->correr($a);

        $plantilla = $this->enviado();
        $this->assertSame('confirmacion_lote', $plantilla['name']);
        $this->assertCount(6, $plantilla['components'][0]['parameters']);
        $this->assertSame(1, WhatsappMensaje::where('tipo', 'confirmacion')->count());
        $this->assertSame($a->id, WhatsappMensaje::first()->turno_id);
        $this->assertStringContainsString('tus turnos en', WhatsappMensaje::first()->mensaje);
    }

    public function test_con_coordenadas_usa_la_plantilla_de_lote_mapa_con_header(): void
    {
        $this->user->update(['latitud' => -27.4692, 'longitud' => -58.8306]);
        [$a] = $this->lote();

        $this->correr($a);

        $plantilla = $this->enviado();
        $this->assertSame('confirmacion_lote_mapa', $plantilla['name']);
        $this->assertSame('header', $plantilla['components'][0]['type']);
    }

    public function test_con_sena_usa_la_plantilla_de_sena_de_lote_con_el_monto_por_turno(): void
    {
        $this->conSena();
        [$a] = $this->lote();

        $this->correr($a);

        $plantilla = $this->enviado();
        $params = $plantilla['components'][0]['parameters'];
        $this->assertSame('reserva_lote_sena_v2', $plantilla['name']);
        $this->assertCount(8, $params);
        $this->assertSame('$5.000,00', $params[4]['text']);
    }

    public function test_con_sena_y_coordenadas_usa_la_plantilla_sena_mapa_v2(): void
    {
        $this->conSena();
        $this->user->update(['latitud' => -27.4692, 'longitud' => -58.8306]);
        [$a] = $this->lote();

        $this->correr($a);

        $this->assertSame('reserva_lote_sena_mapa_v2', $this->enviado()['name']);
    }

    public function test_con_varias_profesionales_el_profesional_es_el_equipo(): void
    {
        $laura = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Laura', 'activo' => true]);
        [$a] = $this->lote($laura);

        $this->correr($a);

        $this->assertSame('el equipo', $this->enviado()['components'][0]['parameters'][4]['text']);
    }

    public function test_config_de_sena_incompleta_cae_a_confirmacion_lote(): void
    {
        $this->conSena(['sena_monto' => null]);
        [$a] = $this->lote();

        $this->correr($a);

        $this->assertSame('confirmacion_lote', $this->enviado()['name']);
    }

    public function test_un_lote_con_un_solo_turno_vigente_usa_la_plantilla_individual_de_ese_turno(): void
    {
        [$a, $b] = $this->lote();
        $a->update(['estado' => 'cancelado']);

        // Dispatched for the first turno, which is the cancelled one.
        $this->correr($a);

        $plantilla = $this->enviado();
        $this->assertSame('confirmacion_turno', $plantilla['name']);
        $this->assertCount(8, $plantilla['components'][0]['parameters']);
        $this->assertSame($b->id, WhatsappMensaje::first()->turno_id);
    }

    public function test_un_lote_con_un_solo_turno_vigente_y_sena_usa_la_sena_individual(): void
    {
        $this->conSena();
        [$a, $b] = $this->lote();
        $b->update(['estado' => 'cancelado']);

        $this->correr($a);

        $this->assertSame('reserva_turno_sena_v2', $this->enviado()['name']);
    }

    public function test_si_se_cancela_uno_antes_de_enviar_el_lote_sigue_combinado_con_los_vigentes(): void
    {
        [$a] = $this->lote();
        $c = $this->turno(now()->addDays(6)->setTime(11, 0)->format('Y-m-d H:i:s'), $a->lote_id);
        $c->update(['estado' => 'cancelado']);

        $this->correr($a);

        $texto = WhatsappMensaje::first()->mensaje;
        $this->assertSame('confirmacion_lote', $this->enviado()['name']);
        $this->assertStringNotContainsString(now()->addDays(6)->format('d/m'), $texto);
    }

    public function test_dos_ejecuciones_no_duplican_el_mensaje_del_lote(): void
    {
        [$a, $b] = $this->lote();

        $this->correr($a);
        $this->correr($a);
        $this->correr($b);

        $this->assertSame(1, WhatsappMensaje::where('tipo', 'confirmacion')->count());
        Http::assertSentCount(1);
    }

    public function test_si_el_texto_supera_el_limite_manda_una_confirmacion_individual_por_turno(): void
    {
        Queue::fake();
        $largo = Servicio::create(['user_id' => $this->user->id, 'nombre' => str_repeat('Servicio largo ', 8), 'duracion_minutos' => 30, 'precio' => 1000, 'activo' => true]);
        $id = (string) Str::uuid();
        $turnos = [];
        foreach (range(1, 6) as $i) {
            $turnos[] = $this->turno(now()->addDays($i)->setTime(10, 0)->format('Y-m-d H:i:s'), $id, $largo);
        }

        $this->correr($turnos[0]);

        Http::assertNothingSent();
        Queue::assertPushed(EnviarMensajeConfirmacion::class, 6);

        // The individual jobs must not block each other through the lote dedup.
        Queue::pushed(EnviarMensajeConfirmacion::class)->each(
            fn (EnviarMensajeConfirmacion $job) => $job->handle(app(CloudApiService::class)),
        );

        $this->assertSame(6, WhatsappMensaje::where('tipo', 'confirmacion')->count());
        Http::assertSentCount(6);
        Http::assertSent(fn ($request) => $request->data()['template']['name'] === 'confirmacion_turno');
    }

    public function test_el_opt_out_corta_el_envio_del_lote(): void
    {
        $this->cliente->update(['whatsapp_opt_out' => true]);
        [$a] = $this->lote();

        $this->correr($a);

        Http::assertNothingSent();
    }

    public function test_la_confirmacion_automatica_apagada_corta_el_envio_del_lote(): void
    {
        $this->user->update(['confirmacion_automatica' => false]);
        [$a] = $this->lote();

        $this->correr($a);

        Http::assertNothingSent();
    }

    public function test_la_suscripcion_vencida_corta_el_envio_del_lote(): void
    {
        $this->user->update(['is_exempt' => false]);
        Subscription::create(['user_id' => $this->user->id, 'ends_at' => now()->subDay(), 'status' => 'VENCIDO']);
        [$a] = $this->lote();

        $this->correr($a);

        Http::assertNothingSent();
    }

    public function test_el_envio_manual_corta_el_envio_del_lote(): void
    {
        $this->user->update(['telefono' => '']);
        [$a] = $this->lote();

        $this->correr($a);

        Http::assertNothingSent();
    }

    public function test_un_telefono_con_formato_invalido_corta_el_envio_del_lote(): void
    {
        $this->cliente->update(['telefono' => '3765252395']);
        [$a] = $this->lote();

        $this->correr($a);

        Http::assertNothingSent();
    }

    public function test_la_reprogramacion_de_un_turno_del_lote_sigue_siendo_individual(): void
    {
        [$a, $b] = $this->lote();

        $this->correr($b, 'reprogramacion');

        $this->assertSame('confirmacion_turno', $this->enviado()['name']);
        $this->assertSame(1, WhatsappMensaje::where('tipo', 'reprogramacion')->where('turno_id', $b->id)->count());
    }

    public function test_el_recordatorio_sigue_siendo_uno_por_turno_en_un_lote(): void
    {
        $id = (string) Str::uuid();
        $this->user->update(['recordatorio_automatico' => true, 'hora_recordatorio' => now()->format('H:00')]);
        $this->turno(now()->addDay()->setTime(10, 0)->format('Y-m-d H:i:s'), $id);
        $this->turno(now()->addDay()->setTime(12, 0)->format('Y-m-d H:i:s'), $id);

        $this->artisan('recordatorios:enviar');

        $this->assertSame(2, WhatsappMensaje::where('tipo', 'recordatorio')->count());
    }
}

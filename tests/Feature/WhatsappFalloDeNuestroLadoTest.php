<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Jobs\EnviarPushFalloWhatsapp;
use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Services\CloudApiService;
use App\Services\Push\FalloWhatsappPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// Fallos de NUESTRO lado (Meta rechaza el envío, o el job agota sus reintentos):
// no hay webhook de Meta que avise, así que el push de fallo se encola desde
// el propio job / comando y queda una fila 'failed' como rastro.
class WhatsappFalloDeNuestroLadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(CloudApiService::CACHE_KEY_SALUD);
        config([
            'services.whatsapp_cloud.token' => 'fake-token',
            'services.whatsapp_cloud.phone_number_id' => '123456',
            'services.whatsapp_cloud.api_version' => 'v26.0',
        ]);
    }

    private function turno(array $userExtra = []): Turno
    {
        $user = User::factory()->create($userExtra + [
            'is_exempt' => true,
            'telefono' => '+543765111111',
        ]);

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '+543765252395',
        ]);

        return Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addDay()->setTime(10, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
    }

    // ── F3: Meta rechaza el envío ────────────────────────────────

    public function test_confirmacion_rechazada_por_meta_encola_el_push_de_fallo(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]], 401)]);
        $turno = $this->turno();

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        $registro = WhatsappMensaje::where('turno_id', $turno->id)->firstOrFail();
        $this->assertSame('failed', $registro->status);
        $this->assertNull($registro->message_id);
        Queue::assertPushed(EnviarPushFalloWhatsapp::class, 1);
        Queue::assertPushed(EnviarPushFalloWhatsapp::class, fn ($job) => $job->mensajeId === $registro->id);
    }

    public function test_confirmacion_enviada_no_encola_el_push_de_fallo(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);
        $turno = $this->turno();

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        $this->assertSame('pending', WhatsappMensaje::where('turno_id', $turno->id)->value('status'));
        Queue::assertNotPushed(EnviarPushFalloWhatsapp::class);
    }

    public function test_recordatorio_rechazado_por_meta_encola_el_push_de_fallo(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template rejected']], 400)]);
        $turno = $this->turno([
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
        ]);

        $this->artisan('recordatorios:enviar');

        $registro = WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'recordatorio')->firstOrFail();
        $this->assertSame('failed', $registro->status);
        $this->assertNull($registro->message_id);
        Queue::assertPushed(EnviarPushFalloWhatsapp::class, 1);
        Queue::assertPushed(EnviarPushFalloWhatsapp::class, fn ($job) => $job->mensajeId === $registro->id);
    }

    public function test_recordatorio_enviado_no_encola_el_push_de_fallo(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.REC']]], 200)]);
        $this->turno([
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
        ]);

        $this->artisan('recordatorios:enviar');

        Queue::assertNotPushed(EnviarPushFalloWhatsapp::class);
    }

    public function test_el_payload_del_push_funciona_con_una_fila_fallida_sin_datos_de_error(): void
    {
        $turno = $this->turno();
        $mensaje = WhatsappMensaje::create([
            'user_id' => $turno->user_id,
            'turno_id' => $turno->id,
            'numero' => '543765252395',
            'mensaje' => 'texto',
            'tipo' => 'confirmacion',
            'message_id' => null,
            'status' => 'failed',
            'status_code' => 401,
            'respuesta_api' => ['error' => ['message' => 'Invalid OAuth access token']],
        ]);

        $payload = FalloWhatsappPayload::para($mensaje->load('turno.cliente', 'turno.servicios'));

        $this->assertSame('No se pudo enviar la confirmación', $payload['title']);
        $this->assertSame('/agenda/notificaciones/' . $mensaje->id, $payload['url']);
        $this->assertStringContainsString('Ana', $payload['body']);
    }

    public function test_un_problema_de_cola_no_rompe_el_job_de_confirmacion(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'x']], 400)]);
        $this->mock(\Illuminate\Contracts\Bus\Dispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->andReturnUsing(function ($job) {
                if ($job instanceof EnviarPushFalloWhatsapp) {
                    throw new \RuntimeException('queue down');
                }

                return 0;
            });
        });
        $turno = $this->turno();

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        $this->assertSame('failed', WhatsappMensaje::where('turno_id', $turno->id)->value('status'));
    }

    // ── F4: reintentos agotados ──────────────────────────────────

    public function test_failed_deja_una_fila_fallida_y_encola_el_push_cuando_no_hay_registro(): void
    {
        Queue::fake();
        $turno = $this->turno();

        (new EnviarMensajeConfirmacion($turno->id))->failed(new ConnectionException('cURL error 28: Operation timed out'));

        $registro = WhatsappMensaje::where('turno_id', $turno->id)->firstOrFail();
        $this->assertSame('failed', $registro->status);
        $this->assertNull($registro->message_id);
        $this->assertSame('confirmacion', $registro->tipo);
        $this->assertSame($turno->user_id, $registro->user_id);
        $this->assertSame('543765252395', $registro->numero);
        $this->assertNotEmpty($registro->error_titulo);
        Queue::assertPushed(EnviarPushFalloWhatsapp::class, 1);
        Queue::assertPushed(EnviarPushFalloWhatsapp::class, fn ($job) => $job->mensajeId === $registro->id);
    }

    public function test_failed_respeta_el_tipo_del_job(): void
    {
        Queue::fake();
        $turno = $this->turno();

        (new EnviarMensajeConfirmacion($turno->id, 'reprogramacion'))->failed(new \RuntimeException('boom'));

        $this->assertSame('reprogramacion', WhatsappMensaje::where('turno_id', $turno->id)->value('tipo'));
    }

    public function test_failed_no_duplica_si_ya_existe_un_registro(): void
    {
        Queue::fake();
        $turno = $this->turno();
        WhatsappMensaje::create([
            'user_id' => $turno->user_id,
            'turno_id' => $turno->id,
            'numero' => '543765252395',
            'mensaje' => 'texto',
            'tipo' => 'confirmacion',
            'message_id' => 'wamid.YA',
            'status' => 'pending',
        ]);

        (new EnviarMensajeConfirmacion($turno->id))->failed(new \RuntimeException('boom'));

        $this->assertSame(1, WhatsappMensaje::where('turno_id', $turno->id)->count());
        $this->assertSame('pending', WhatsappMensaje::where('turno_id', $turno->id)->value('status'));
        Queue::assertNotPushed(EnviarPushFalloWhatsapp::class);
    }

    public function test_failed_no_hace_nada_si_el_turno_ya_no_existe(): void
    {
        Queue::fake();

        (new EnviarMensajeConfirmacion(999999))->failed(new \RuntimeException('boom'));

        $this->assertSame(0, WhatsappMensaje::count());
        Queue::assertNotPushed(EnviarPushFalloWhatsapp::class);
    }

    public function test_failed_no_registra_nada_si_el_envio_estaba_omitido_por_regla(): void
    {
        Queue::fake();
        $turno = $this->turno();
        $turno->cliente->update(['whatsapp_opt_out' => true]);

        (new EnviarMensajeConfirmacion($turno->id))->failed(new \RuntimeException('boom'));

        $this->assertSame(0, WhatsappMensaje::count());
        Queue::assertNotPushed(EnviarPushFalloWhatsapp::class);
    }

    public function test_un_timeout_de_red_lanza_y_failed_deja_el_rastro(): void
    {
        Queue::fake();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $turno = $this->turno();
        $job = new EnviarMensajeConfirmacion($turno->id);

        try {
            $job->handle(app(CloudApiService::class));
            $this->fail('El job debía lanzar para que la cola lo reintente');
        } catch (ConnectionException $e) {
            $this->assertSame(0, WhatsappMensaje::count());
            $job->failed($e);
        }

        $this->assertSame('failed', WhatsappMensaje::where('turno_id', $turno->id)->value('status'));
        Queue::assertPushed(EnviarPushFalloWhatsapp::class, 1);
    }

    // ── F4: timeout explícito en el envío de plantilla ──────────

    public function test_enviar_plantilla_fija_un_timeout_explicito(): void
    {
        $timeouts = [];
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T']]], 200)]);
        Http::globalMiddleware(function ($handler) use (&$timeouts) {
            return function ($request, array $options) use ($handler, &$timeouts) {
                $timeouts[] = $options['timeout'] ?? null;

                return $handler($request, $options);
            };
        });

        app(CloudApiService::class)->enviarPlantilla('543765252395', 'confirmacion_turno', 'es_AR', ['a']);

        $this->assertSame([15], $timeouts);
    }
}

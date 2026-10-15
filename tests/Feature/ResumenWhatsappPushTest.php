<?php

namespace Tests\Feature;

use App\Jobs\EnviarPushResumenWhatsapp;
use App\Models\Cliente;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Notifications\ResumenMensajesWhatsapp;
use App\Services\Push\ResumenWhatsappPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ResumenWhatsappPushTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function suscribir(): void
    {
        $this->user->updatePushSubscription('https://push.example/a', 'k', 't');
    }

    private function mensajes(int $cantidad, string $tipo, string $status, ?User $user = null, bool $deAyer = false): void
    {
        foreach (range(1, $cantidad) as $i) {
            $m = WhatsappMensaje::create([
                'user_id' => ($user ?? $this->user)->id,
                'numero' => '5493765252395',
                'mensaje' => 'texto',
                'tipo' => $tipo,
                'message_id' => 'wamid.' . uniqid('', true),
                'status' => $status,
            ]);

            if ($deAyer) {
                $m->forceFill(['created_at' => now()->subDay()])->save();
            }
        }
    }

    private function ejecutar(string $categoria = 'confirmaciones'): void
    {
        (new EnviarPushResumenWhatsapp($this->user->id, $categoria))->handle();
    }

    private function payloadEnviado(): array
    {
        $payload = null;
        Notification::assertSentTo($this->user, ResumenMensajesWhatsapp::class, function (ResumenMensajesWhatsapp $n) use (&$payload) {
            $payload = $n->payload;

            return true;
        });

        return $payload;
    }

    // -- Job ----------------------------------------------------------

    public function test_one_delivered_message_is_singular_and_silent(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(1, 'confirmacion', 'delivered');

        $this->ejecutar();

        Notification::assertSentToTimes($this->user, ResumenMensajesWhatsapp::class, 1);
        $p = $this->payloadEnviado();
        $this->assertSame('Confirmaciones enviadas', $p['title']);
        $this->assertSame('1 entregado', $p['body']);
        $this->assertSame('/agenda', $p['url']);
        $this->assertSame('resumen-whatsapp-confirmaciones-' . now()->toDateString(), $p['tag']);
        $this->assertTrue($p['silencioso']);
        $this->assertIsInt($p['timestamp']);
        $this->assertSame(['title', 'body', 'url', 'tag', 'timestamp', 'silencioso'], array_keys($p));
    }

    public function test_many_delivered_messages_are_plural_and_read_counts_as_delivered(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(3, 'confirmacion', 'delivered');
        $this->mensajes(2, 'reprogramacion', 'read');

        $this->ejecutar();

        $this->assertSame('5 entregados', $this->payloadEnviado()['body']);
    }

    public function test_failures_are_appended_with_singular_and_plural(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(4, 'confirmacion', 'delivered');
        $this->mensajes(1, 'confirmacion', 'failed');

        $this->ejecutar();
        $this->assertSame('4 entregados · 1 falló', $this->payloadEnviado()['body']);

        $this->mensajes(1, 'confirmacion', 'failed');
        Notification::fake();

        $this->ejecutar();
        $this->assertSame('4 entregados · 2 fallaron', $this->payloadEnviado()['body']);
    }

    public function test_one_delivered_with_failures_keeps_the_singular(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(1, 'recordatorio', 'delivered');
        $this->mensajes(3, 'recordatorio', 'failed');

        $this->ejecutar('recordatorios');

        $p = $this->payloadEnviado();
        $this->assertSame('Recordatorios enviados', $p['title']);
        $this->assertSame('1 entregado · 3 fallaron', $p['body']);
        $this->assertSame('resumen-whatsapp-recordatorios-' . now()->toDateString(), $p['tag']);
    }

    public function test_it_counts_only_the_requested_category(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(2, 'confirmacion', 'delivered');
        $this->mensajes(7, 'recordatorio', 'delivered');
        $this->mensajes(4, 'cancelacion', 'delivered');
        $this->mensajes(1, 'recordatorio', 'failed');

        $this->ejecutar('confirmaciones');

        $this->assertSame('2 entregados', $this->payloadEnviado()['body']);
    }

    public function test_it_counts_only_todays_messages_and_only_this_user(): void
    {
        Notification::fake();
        $this->suscribir();
        $otra = User::factory()->create();
        $this->mensajes(2, 'confirmacion', 'delivered');
        $this->mensajes(5, 'confirmacion', 'delivered', null, true);
        $this->mensajes(1, 'confirmacion', 'failed', null, true);
        $this->mensajes(6, 'confirmacion', 'delivered', $otra);

        $this->ejecutar();

        $this->assertSame('2 entregados', $this->payloadEnviado()['body']);
    }

    public function test_nothing_is_sent_when_nothing_was_delivered(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(3, 'confirmacion', 'failed');
        $this->mensajes(2, 'confirmacion', 'pending');
        $this->mensajes(4, 'confirmacion', 'delivered', null, true);

        $this->ejecutar();

        Notification::assertNothingSent();
    }

    public function test_nothing_is_sent_without_subscriptions(): void
    {
        Notification::fake();
        $this->mensajes(2, 'confirmacion', 'delivered');

        $this->ejecutar();

        Notification::assertNothingSent();
    }

    public function test_a_missing_user_or_unknown_category_is_a_no_op(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(2, 'confirmacion', 'delivered');

        (new EnviarPushResumenWhatsapp(999999, 'confirmaciones'))->handle();
        $this->ejecutar('inexistente');

        Notification::assertNothingSent();
    }

    // -- Names (turno -> cliente) ---------------------------------------

    // 2099-10-21 is a Wednesday.
    private const FECHA = '2099-10-21';

    private int $segundos = 0;

    private int $telefonos = 0;

    /** Message tied to a fresh turno; later calls count as more recently updated. */
    private function conTurno(
        string $nombre,
        ?string $apellido,
        array $servicios = ['Esmaltado'],
        string $tipo = 'confirmacion',
        string $status = 'delivered',
        string $hora = '15:00:00',
        ?Cliente $cliente = null,
    ): WhatsappMensaje {
        $cliente ??= Cliente::create([
            'user_id' => $this->user->id, 'nombre' => $nombre, 'apellido' => $apellido, 'telefono' => '+549376' . str_pad((string) ++$this->telefonos, 7, '0', STR_PAD_LEFT),
        ]);
        $turno = Turno::create([
            'user_id' => $this->user->id, 'cliente_id' => $cliente->id,
            'fecha_hora' => self::FECHA . ' ' . $hora, 'duracion_total_minutos' => 60, 'estado' => 'confirmado', 'origen' => 'app',
        ]);
        $ids = array_map(fn (string $n) => Servicio::create([
            'user_id' => $this->user->id, 'nombre' => $n, 'precio' => 1000, 'duracion_minutos' => 60,
        ])->id, $servicios);
        $turno->servicios()->attach($ids);

        $mensaje = WhatsappMensaje::create([
            'user_id' => $this->user->id,
            'turno_id' => $turno->id,
            'numero' => '5493765252395',
            'mensaje' => 'texto',
            'tipo' => $tipo,
            'message_id' => 'wamid.' . uniqid('', true),
            'status' => $status,
        ]);
        $mensaje->forceFill(['updated_at' => now()->addSeconds(++$this->segundos)])->saveQuietly();

        return $mensaje;
    }

    public function test_one_delivered_names_the_client_services_and_raw_wall_clock_time(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->conTurno('Camila', 'Rodriguez', ['Esmaltado', 'Pedicuría']);

        $this->ejecutar();

        $p = $this->payloadEnviado();
        $this->assertSame('Confirmación entregada', $p['title']);
        $this->assertSame("Camila R. · Esmaltado + 1 más\nMié 21 oct · 15:00", $p['body']);
        $this->assertSame('/agenda', $p['url']);
        $this->assertSame('resumen-whatsapp-confirmaciones-' . now()->toDateString(), $p['tag']);
        $this->assertTrue($p['silencioso']);
        $this->assertSame(['title', 'body', 'url', 'tag', 'timestamp', 'silencioso'], array_keys($p));
    }

    public function test_one_delivered_with_many_services_collapses_them_and_keeps_early_hours(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->conTurno('Ana', 'Pérez', ['Esmaltado', 'Pedicuría', 'Cejas'], 'recordatorio', 'delivered', '00:30:00');

        $this->ejecutar('recordatorios');

        $p = $this->payloadEnviado();
        $this->assertSame('Recordatorio entregado', $p['title']);
        $this->assertSame("Ana P. · Esmaltado + 2 más\nMié 21 oct · 00:30", $p['body']);
    }

    public function test_one_delivered_appends_the_failures_to_the_second_line(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->conTurno('Camila', 'Rodriguez');
        $this->conTurno('Lucía', 'Fernández', ['Esmaltado'], 'confirmacion', 'failed');

        $this->ejecutar();
        $this->assertSame("Camila R. · Esmaltado\nMié 21 oct · 15:00 · 1 falló", $this->payloadEnviado()['body']);

        $this->conTurno('Ana', 'Pérez', ['Esmaltado'], 'confirmacion', 'failed');
        Notification::fake();

        $this->ejecutar();
        $this->assertSame("Camila R. · Esmaltado\nMié 21 oct · 15:00 · 2 fallaron", $this->payloadEnviado()['body']);
    }

    public function test_two_delivered_list_both_names_most_recent_first(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->conTurno('Lucía', 'Fernández');
        $this->conTurno('Camila', 'Rodriguez', ['Esmaltado'], 'reprogramacion', 'read');

        $this->ejecutar();

        $p = $this->payloadEnviado();
        $this->assertSame('Confirmaciones entregadas', $p['title']);
        $this->assertSame("Camila R. y Lucía F.\n2 entregados", $p['body']);
    }

    public function test_three_delivered_are_joined_with_commas_and_y_plus_failures(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->conTurno('Ana', 'Pérez');
        $this->conTurno('Lucía', 'Fernández');
        $this->conTurno('Camila', 'Rodriguez');
        $this->conTurno('Sofía', 'Gómez', ['Esmaltado'], 'confirmacion', 'failed');
        $this->conTurno('Eva', 'Díaz', ['Esmaltado'], 'confirmacion', 'failed');

        $this->ejecutar();

        $p = $this->payloadEnviado();
        $this->assertSame('Confirmaciones entregadas', $p['title']);
        $this->assertSame("Camila R., Lucía F. y Ana P.\n3 entregados · 2 fallaron", $p['body']);
    }

    public function test_five_delivered_show_two_names_and_how_many_more(): void
    {
        Notification::fake();
        $this->suscribir();
        foreach (['Ana', 'Eva', 'Sofía', 'Lucía', 'Camila'] as $nombre) {
            $this->conTurno($nombre, 'Test');
        }

        $this->ejecutar();

        $p = $this->payloadEnviado();
        $this->assertSame('Confirmaciones entregadas', $p['title']);
        $this->assertSame("Camila T., Lucía T. y 3 más\n5 entregados", $p['body']);
    }

    public function test_four_delivered_say_two_more_and_clients_without_surname_show_the_first_name(): void
    {
        Notification::fake();
        $this->suscribir();
        foreach (['Ana', 'Eva', 'Lucía', 'Camila'] as $nombre) {
            $this->conTurno($nombre, null);
        }

        $this->ejecutar();

        $this->assertSame("Camila, Lucía y 2 más\n4 entregados", $this->payloadEnviado()['body']);
    }

    public function test_the_same_client_is_listed_once(): void
    {
        Notification::fake();
        $this->suscribir();
        $camila = Cliente::create([
            'user_id' => $this->user->id, 'nombre' => 'Camila', 'apellido' => 'Rodriguez', 'telefono' => '+5493765252395',
        ]);
        $this->conTurno('', null, ['Esmaltado'], 'confirmacion', 'delivered', '10:00:00', $camila);
        $this->conTurno('', null, ['Pedicuría'], 'reprogramacion', 'read', '16:45:00', $camila);

        $this->ejecutar();

        $p = $this->payloadEnviado();
        $this->assertSame('Confirmación entregada', $p['title']);
        // The turno of the client's most recent message wins.
        $this->assertSame("Camila R. · Pedicuría\nMié 21 oct · 16:45", $p['body']);
    }

    public function test_names_only_come_from_the_requested_category(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->conTurno('Camila', 'Rodriguez', ['Esmaltado'], 'confirmacion');
        $this->conTurno('Lucía', 'Fernández', ['Esmaltado'], 'recordatorio');
        $this->conTurno('Ana', 'Pérez', ['Esmaltado'], 'cancelacion');

        $this->ejecutar('recordatorios');

        $p = $this->payloadEnviado();
        $this->assertSame('Recordatorio entregado', $p['title']);
        $this->assertSame("Lucía F. · Esmaltado\nMié 21 oct · 15:00", $p['body']);
        $this->assertSame('resumen-whatsapp-recordatorios-' . now()->toDateString(), $p['tag']);
    }

    public function test_messages_without_turno_are_ignored_when_others_have_names(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(2, 'confirmacion', 'delivered');
        $this->conTurno('Camila', 'Rodriguez');

        $this->ejecutar();

        $p = $this->payloadEnviado();
        $this->assertSame('Confirmación entregada', $p['title']);
        $this->assertStringStartsWith("Camila R. · Esmaltado\n", $p['body']);
    }

    public function test_it_falls_back_to_the_counts_when_no_name_can_be_resolved(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->mensajes(3, 'confirmacion', 'delivered');
        $this->mensajes(1, 'confirmacion', 'failed');

        $this->ejecutar();

        $p = $this->payloadEnviado();
        $this->assertSame('Confirmaciones enviadas', $p['title']);
        $this->assertSame('3 entregados · 1 falló', $p['body']);
        $this->assertTrue($p['silencioso']);
    }

    public function test_nothing_is_sent_when_only_failures_have_names(): void
    {
        Notification::fake();
        $this->suscribir();
        $this->conTurno('Camila', 'Rodriguez', ['Esmaltado'], 'confirmacion', 'failed');

        $this->ejecutar();

        Notification::assertNothingSent();
    }

    // -- Notification -------------------------------------------------

    public function test_notification_uses_a_six_hour_ttl_and_normal_urgency(): void
    {
        $payload = ResumenWhatsappPayload::para('confirmaciones', 1, 0);
        $n = new ResumenMensajesWhatsapp($payload);

        $message = $n->toWebPush($this->user, $n);

        $this->assertSame(['TTL' => 21600, 'urgency' => 'normal'], $message->getOptions());
        $this->assertSame($payload, $message->toArray());
    }
}

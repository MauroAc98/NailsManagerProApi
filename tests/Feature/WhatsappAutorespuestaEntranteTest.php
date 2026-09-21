<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use App\Models\WhatsappConnection;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El numero de Cloud API es compartido y solo manda avisos automaticos: nadie
 * lee lo que las clientas le contestan. La respuesta automatica les avisa y les
 * da el telefono del negocio al que pertenece su turno.
 */
class WhatsappAutorespuestaEntranteTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'app-secret-de-test';
    private const NUMERO_COMPARTIDO = 'PN-COMPARTIDO';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.whatsapp_cloud.app_secret' => self::APP_SECRET,
            'services.whatsapp_cloud.token' => 'token-compartido',
            'services.whatsapp_cloud.phone_number_id' => self::NUMERO_COMPARTIDO,
            'services.whatsapp_cloud.api_version' => 'v26.0',
            'services.whatsapp_cloud.autorespuesta_habilitada' => true,
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SALIENTE']]], 200)]);
    }

    private function postSigned(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhooks/whatsapp-cloud', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function entrante(string $from, array $mensaje = [], string $phoneNumberId = self::NUMERO_COMPARTIDO): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-id',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => $phoneNumberId, 'display_phone_number' => '5493764000000'],
                        'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Clienta']]],
                        'messages' => [array_merge([
                            'from' => $from,
                            'id' => 'wamid.ENTRANTE'.uniqid(),
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => 'hola, puedo cambiar mi turno?'],
                        ], $mensaje)],
                    ],
                ]],
            ]],
        ];
    }

    private function negocio(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Nails by Natalia',
            'telefono' => '+54 376 4123456',
            'locale' => 'es',
        ], $attrs));
    }

    private function mensajeEnviado(User $user, string $numero, string $messageId = 'wamid.NUESTRO'): WhatsappMensaje
    {
        return WhatsappMensaje::create([
            'user_id' => $user->id,
            'numero' => $numero,
            'provider' => 'cloud_api',
            'mensaje' => 'recordatorio',
            'tipo' => 'recordatorio',
            'message_id' => $messageId,
            'status' => 'delivered',
        ]);
    }

    private function enviosDeTexto(): array
    {
        return collect(Http::recorded())
            ->map(fn ($par) => $par[0])
            ->filter(fn (HttpRequest $r) => ($r['type'] ?? null) === 'text')
            ->values()
            ->all();
    }

    public function test_responde_con_el_nombre_y_el_telefono_del_negocio_cuando_contesta_a_un_mensaje_nuestro(): void
    {
        $user = $this->negocio();
        $this->mensajeEnviado($user, '5493764123456', 'wamid.NUESTRO');

        $this->postSigned($this->entrante('5493764123456', ['context' => ['id' => 'wamid.NUESTRO']]))->assertOk();

        $envios = $this->enviosDeTexto();
        $this->assertCount(1, $envios);
        $this->assertStringContainsString('/'.self::NUMERO_COMPARTIDO.'/messages', $envios[0]->url());
        $this->assertSame('5493764123456', $envios[0]['to']);
        $texto = $envios[0]['text']['body'];
        $this->assertStringContainsString('Nails by Natalia', $texto);
        $this->assertStringContainsString('+54 376 4123456', $texto);
        $this->assertStringContainsString('https://wa.me/543764123456', $texto);
        $this->assertStringContainsString('no recibe mensajes', $texto);
        // Le dice a la clienta QUE hacer con el link, no solo lo pega.
        $this->assertStringContainsString('tocá este link', $texto);
        $this->assertStringContainsString('chat directo', $texto);
    }

    public function test_sin_context_encuentra_el_negocio_por_el_ultimo_mensaje_enviado_a_ese_numero(): void
    {
        $user = $this->negocio();
        $this->mensajeEnviado($user, '5493764123456');

        $this->postSigned($this->entrante('5493764123456'))->assertOk();

        $this->assertStringContainsString('Nails by Natalia', $this->enviosDeTexto()[0]['text']['body']);
    }

    public function test_el_9_de_los_celulares_argentinos_no_impide_encontrar_al_negocio(): void
    {
        $user = $this->negocio();
        // Se guardo sin el 9 (como lo carga la duena), pero Meta manda el wa_id con 9.
        $this->mensajeEnviado($user, '543764123456');

        $this->postSigned($this->entrante('5493764123456'))->assertOk();

        $this->assertStringContainsString('Nails by Natalia', $this->enviosDeTexto()[0]['text']['body']);
    }

    public function test_con_varios_negocios_elige_el_que_le_escribio_ultimo(): void
    {
        $viejo = $this->negocio(['name' => 'Negocio Viejo']);
        $nuevo = $this->negocio(['name' => 'Negocio Nuevo', 'telefono' => '+54 376 4999999']);
        $this->mensajeEnviado($viejo, '5493764123456', 'wamid.VIEJO');
        $this->mensajeEnviado($nuevo, '5493764123456', 'wamid.NUEVO');

        $this->postSigned($this->entrante('5493764123456'))->assertOk();

        $texto = $this->enviosDeTexto()[0]['text']['body'];
        $this->assertStringContainsString('Negocio Nuevo', $texto);
        $this->assertStringNotContainsString('Negocio Viejo', $texto);
    }

    public function test_si_el_negocio_no_tiene_telefono_igual_avisa_sin_inventar_un_numero(): void
    {
        $user = $this->negocio(['telefono' => null]);
        $this->mensajeEnviado($user, '5493764123456');

        $this->postSigned($this->entrante('5493764123456'))->assertOk();

        $texto = $this->enviosDeTexto()[0]['text']['body'];
        $this->assertStringContainsString('Nails by Natalia', $texto);
        $this->assertStringNotContainsString('wa.me', $texto);
    }

    public function test_un_remitente_desconocido_recibe_un_aviso_generico(): void
    {
        $this->negocio();

        $this->postSigned($this->entrante('5493765000111'))->assertOk();

        $envios = $this->enviosDeTexto();
        $this->assertCount(1, $envios);
        $this->assertStringContainsString('no recibe mensajes', $envios[0]['text']['body']);
        $this->assertStringNotContainsString('Nails by Natalia', $envios[0]['text']['body']);
    }

    public function test_responde_en_portugues_si_el_negocio_usa_pt_br(): void
    {
        $user = $this->negocio(['locale' => 'pt-BR']);
        $this->mensajeEnviado($user, '5493764123456');

        $this->postSigned($this->entrante('5493764123456'))->assertOk();

        $texto = $this->enviosDeTexto()[0]['text']['body'];
        $this->assertStringContainsString('não recebe mensagens', $texto);
        $this->assertStringContainsString('toque neste link', $texto);
    }

    public function test_no_repite_la_respuesta_a_la_misma_persona_dentro_de_las_24_horas(): void
    {
        $user = $this->negocio();
        $this->mensajeEnviado($user, '5493764123456');

        $this->postSigned($this->entrante('5493764123456'))->assertOk();
        $this->postSigned($this->entrante('5493764123456'))->assertOk();
        $this->postSigned($this->entrante('5493764123456', ['type' => 'image']))->assertOk();

        $this->assertCount(1, $this->enviosDeTexto());
    }

    public function test_no_responde_a_reacciones(): void
    {
        $user = $this->negocio();
        $this->mensajeEnviado($user, '5493764123456');

        $this->postSigned($this->entrante('5493764123456', ['type' => 'reaction', 'reaction' => ['emoji' => '👍']]))->assertOk();

        $this->assertCount(0, $this->enviosDeTexto());
    }

    public function test_no_responde_a_quien_pidio_no_recibir_mensajes(): void
    {
        $user = $this->negocio();
        $this->mensajeEnviado($user, '5493764123456');
        Cliente::create(['user_id' => $user->id, 'nombre' => 'Baja', 'telefono' => '3764123456', 'whatsapp_opt_out' => true]);

        $this->postSigned($this->entrante('5493764123456'))->assertOk();

        $this->assertCount(0, $this->enviosDeTexto());
    }

    public function test_el_interruptor_de_configuracion_apaga_la_respuesta(): void
    {
        config(['services.whatsapp_cloud.autorespuesta_habilitada' => false]);
        $user = $this->negocio();
        $this->mensajeEnviado($user, '5493764123456');

        $this->postSigned($this->entrante('5493764123456'))->assertOk();

        $this->assertCount(0, $this->enviosDeTexto());
    }

    public function test_no_responde_por_el_numero_propio_de_un_negocio_conectado(): void
    {
        $user = $this->negocio();
        WhatsappConnection::create([
            'user_id' => $user->id,
            'waba_id' => 'WABA-1',
            'phone_number_id' => 'PN-PROPIO',
            'display_phone_number' => '5491199990000',
            'access_token' => 'token-propio',
        ]);
        $this->mensajeEnviado($user, '5493764123456');

        $this->postSigned($this->entrante('5493764123456', [], 'PN-PROPIO'))->assertOk();

        $this->assertCount(0, $this->enviosDeTexto());
    }

    public function test_si_meta_falla_el_webhook_responde_200_y_permite_reintentar(): void
    {
        // Primero Meta falla (500) y despues responde bien: el segundo mensaje de
        // la clienta tiene que poder reintentar (el intento fallido no la
        // "gasta" como respondida).
        // Cliente HTTP nuevo: el fake del setUp (200) tendria prioridad sobre este.
        Http::swap(new HttpFactory());
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'boom']], 500)
            ->push(['messages' => [['id' => 'wamid.OK']]], 200)]);
        $user = $this->negocio();
        $this->mensajeEnviado($user, '5493764123456');

        $this->postSigned($this->entrante('5493764123456'))->assertOk();
        $this->postSigned($this->entrante('5493764123456'))->assertOk();
        // Y un tercero ya no repite: la respuesta buena quedo registrada.
        $this->postSigned($this->entrante('5493764123456'))->assertOk();

        $this->assertCount(2, $this->enviosDeTexto());
    }

    public function test_los_estados_de_entrega_no_disparan_ninguna_respuesta(): void
    {
        $user = $this->negocio();
        $this->mensajeEnviado($user, '5493764123456', 'wamid.NUESTRO');

        $this->postSigned([
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => 'waba-id', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['phone_number_id' => self::NUMERO_COMPARTIDO],
                'statuses' => [['id' => 'wamid.NUESTRO', 'status' => 'read', 'timestamp' => (string) now()->timestamp, 'recipient_id' => '5493764123456']],
            ]]]]],
        ])->assertOk();

        $this->assertCount(0, $this->enviosDeTexto());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Services\CloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnviarRecordatoriosDeduplicacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(CloudApiService::CACHE_KEY_SALUD);
    }

    // Escenario real: la profesional mandó el recordatorio a mano desde
    // /agenda/recordatorios a la mañana (crea un WhatsappMensaje
    // status='manual'). El flag whatsapp_requiere_envio_manual ya está en
    // false para esta cuenta (teléfono y dirección completos, número
    // saludable), así que el comando entra por la rama NORMAL. Sin la
    // deduplicación por (turno_id, tipo), volvería a mandar el mismo
    // recordatorio de forma automática: duplicado real al cliente, y el
    // WhatsappMensaje::create() posterior explota contra el unique
    // constraint (turno_id, tipo), quedando logueado como fallido pese a
    // que en realidad ya estaba resuelto. Esto es independiente de CUÁL
    // sea la razón por la que el flag esté en false — antes dependía de
    // que el ratio de fallos no se disparara, ahora depende de teléfono +
    // dirección + locale + la señal de salud cacheada de Cloud API — la
    // deduplicación protege contra el reenvío sin importar el criterio.
    public function test_no_reenvia_recordatorio_automatico_para_un_turno_ya_gestionado(): void
    {
        Http::fake();

        $user = User::factory()->create([
            'is_exempt' => true,
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
            'telefono' => '3765000000',
            'direccion' => 'San Martin 123',
        ]);

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '3765252395',
        ]);

        $turno = Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addDay()->setTime(10, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        WhatsappMensaje::create([
            'user_id' => $user->id,
            'turno_id' => $turno->id,
            'numero' => '3765252395',
            'mensaje' => '',
            'tipo' => 'recordatorio',
            'status' => 'manual',
        ]);

        $this->artisan('recordatorios:enviar');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendText'));

        $this->assertSame(
            1,
            WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'recordatorio')->count(),
        );
    }
}

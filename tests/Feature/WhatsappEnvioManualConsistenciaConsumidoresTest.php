<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Services\CloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// Cubre el requerimiento "Consistent Gating Across Both Send Consumers": el
// job de confirmación y el comando de recordatorios deben quedar gateados
// de forma idéntica ante el mismo estado de usuario, sin divergencia entre
// los dos call sites. Un mismo usuario, con un único motivo para el flag
// (veredicto de salud cacheado en rojo), procesado por ambos consumidores
// en el mismo test.
class WhatsappEnvioManualConsistenciaConsumidoresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(CloudApiService::CACHE_KEY_SALUD);
    }

    public function test_job_y_comando_quedan_gateados_igual_cuando_la_salud_cacheada_esta_en_rojo(): void
    {
        Http::fake();
        Mail::fake();
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'RED']);

        // Teléfono y dirección completos, locale por defecto -> el único
        // motivo del flag para este usuario es el veredicto de salud
        // cacheado en rojo, compartido por los dos consumidores.
        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '3765000000',
            'direccion' => 'San Martin 123',
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
        ]);

        $this->assertTrue($user->whatsapp_requiere_envio_manual);

        $clienteConfirmacion = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '+543765252395',
        ]);

        $turnoConfirmacion = Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $clienteConfirmacion->id,
            'fecha_hora' => now()->addHours(2),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        $clienteRecordatorio = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Beti',
            'apellido' => 'Diaz',
            'telefono' => '3765252396',
        ]);

        Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $clienteRecordatorio->id,
            'fecha_hora' => now()->addDay()->setTime(10, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        (new EnviarMensajeConfirmacion($turnoConfirmacion->id))->handle(app(CloudApiService::class));
        $this->artisan('recordatorios:enviar');

        // Ninguno de los dos consumidores debe haber intentado un envío
        // automático — ambos leen el mismo atributo, gateado por la misma
        // señal de salud.
        Http::assertNothingSent();

        $this->assertSame(
            0,
            WhatsappMensaje::whereIn('tipo', ['confirmacion', 'recordatorio'])->count(),
        );
    }
}

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
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EnviarRecordatoriosAislamientoPorSalonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(CloudApiService::CACHE_KEY_SALUD);
    }

    private function salonConTurnoManana(array $atributos): User
    {
        $user = User::factory()->create(array_merge([
            'is_exempt' => true,
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
        ], $atributos));

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '+543765252395',
        ]);

        Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addDay()->setTime(10, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        return $user;
    }

    // Un salón en envío manual dispara un mail a la dueña. Si el SMTP falla,
    // esa excepción quedaba fuera de todo try/catch y la corrida entera se
    // cortaba: los salones que venían después nunca recibían sus recordatorios.
    public function test_una_falla_de_mail_en_un_salon_no_corta_los_recordatorios_de_los_demas(): void
    {
        Http::fake();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp caido'));

        // Primero por id: envío manual (sin teléfono) -> intenta mandar mail.
        $this->salonConTurnoManana(['telefono' => null]);
        // Después: salón normal que SÍ debería recibir su recordatorio.
        $normal = $this->salonConTurnoManana(['telefono' => '3765000000']);

        $this->artisan('recordatorios:enviar');

        $this->assertSame(
            1,
            WhatsappMensaje::where('user_id', $normal->id)->where('tipo', 'recordatorio')->count(),
        );
    }

    // Guarda de regresión: saltear a UN cliente (sin teléfono, opt-out, etc.)
    // no puede cortar los recordatorios del resto de los turnos del mismo salón.
    public function test_un_cliente_sin_telefono_no_corta_los_recordatorios_del_resto_del_salon(): void
    {
        Http::fake();

        $user = $this->salonConTurnoManana(['telefono' => '3765000000']);
        $sinTelefono = Cliente::create(['user_id' => $user->id, 'nombre' => 'Sin', 'apellido' => 'Telefono', 'telefono' => '']);
        // id menor que el turno del cliente con teléfono: se procesa primero.
        $primero = Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $sinTelefono->id,
            'fecha_hora' => now()->addDay()->setTime(9, 0),
            'duracion_total_minutos' => 30,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
        Turno::whereKey($primero->id)->update(['id' => 0]);

        $this->artisan('recordatorios:enviar');

        $this->assertSame(
            1,
            WhatsappMensaje::where('user_id', $user->id)->where('tipo', 'recordatorio')->count(),
        );
    }
}

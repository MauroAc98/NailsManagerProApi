<?php

namespace Tests\Feature;

use App\Console\Commands\EnviarRecordatorios;
use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Services\CloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Latido de la última hora procesada: si el scheduler estuvo caído (o un deploy
// cayó justo en el :00), la corrida siguiente recupera las horas salteadas.
class EnviarRecordatoriosHorasPerdidasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(CloudApiService::CACHE_KEY_SALUD);
        Cache::forget(EnviarRecordatorios::CACHE_ULTIMA_HORA);
        config([
            'services.whatsapp_cloud.token' => 'fake-token',
            'services.whatsapp_cloud.phone_number_id' => '123456',
            'services.whatsapp_cloud.api_version' => 'v26.0',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.' . uniqid()]]], 200)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Un salón con recordatorio a $hora y un turno confirmado mañana. */
    private function salon(string $hora): Turno
    {
        $user = User::factory()->create([
            'is_exempt' => true,
            'recordatorio_automatico' => true,
            'hora_recordatorio' => $hora,
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
            'fecha_hora' => Carbon::tomorrow()->setTime(15, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
    }

    private function enviado(Turno $turno): bool
    {
        return WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'recordatorio')->exists();
    }

    private function correrA(string $momento): void
    {
        Carbon::setTestNow($momento);
        $this->artisan('recordatorios:enviar')->assertSuccessful();
    }

    public function test_corrida_normal_procesa_solo_la_hora_actual_y_deja_el_latido(): void
    {
        Carbon::setTestNow('2099-06-10 10:00:00');
        $a = $this->salon('10:00');
        $b = $this->salon('09:00');
        $c = $this->salon('11:00');

        $this->correrA('2099-06-10 10:00:00');

        $this->assertTrue($this->enviado($a));
        $this->assertFalse($this->enviado($b));
        $this->assertFalse($this->enviado($c));
        $this->assertSame('2099-06-10 10:00', Cache::get(EnviarRecordatorios::CACHE_ULTIMA_HORA));
    }

    public function test_el_latido_se_actualiza_aunque_no_haya_salones_en_esa_hora(): void
    {
        $this->correrA('2099-06-10 04:00:00');

        $this->assertSame('2099-06-10 04:00', Cache::get(EnviarRecordatorios::CACHE_ULTIMA_HORA));
    }

    public function test_una_hora_salteada_se_procesa_en_la_corrida_siguiente(): void
    {
        Carbon::setTestNow('2099-06-10 09:00:00');
        $perdida = $this->salon('10:00');
        $actual = $this->salon('11:00');

        $this->correrA('2099-06-10 09:00:00');
        // La corrida de las 10:00 nunca ocurrió (scheduler caído).
        $this->assertFalse($this->enviado($perdida));

        $this->correrA('2099-06-10 11:00:00');

        $this->assertTrue($this->enviado($perdida));
        $this->assertTrue($this->enviado($actual));
        $this->assertSame('2099-06-10 11:00', Cache::get(EnviarRecordatorios::CACHE_ULTIMA_HORA));
    }

    public function test_recupera_hasta_tres_horas_en_total_incluida_la_actual(): void
    {
        Carbon::setTestNow('2099-06-10 07:00:00');
        $h08 = $this->salon('08:00');
        $h09 = $this->salon('09:00');
        $h10 = $this->salon('10:00');

        $this->correrA('2099-06-10 07:00:00');
        $this->correrA('2099-06-10 10:00:00');

        $this->assertTrue($this->enviado($h08));
        $this->assertTrue($this->enviado($h09));
        $this->assertTrue($this->enviado($h10));
    }

    public function test_un_latido_mas_viejo_que_el_tope_solo_procesa_la_hora_actual(): void
    {
        Carbon::setTestNow('2099-06-10 06:00:00');
        $vieja = $this->salon('08:00');
        $actual = $this->salon('10:00');

        $this->correrA('2099-06-10 06:00:00');
        $this->correrA('2099-06-10 10:00:00');

        $this->assertFalse($this->enviado($vieja));
        $this->assertTrue($this->enviado($actual));
    }

    public function test_sin_latido_procesa_solo_la_hora_actual(): void
    {
        Carbon::setTestNow('2099-06-10 10:00:00');
        $anterior = $this->salon('09:00');
        $actual = $this->salon('10:00');

        $this->correrA('2099-06-10 10:00:00');

        $this->assertFalse($this->enviado($anterior));
        $this->assertTrue($this->enviado($actual));
    }

    public function test_no_cruza_la_medianoche(): void
    {
        Carbon::setTestNow('2099-06-10 01:00:00');
        $deAyer = $this->salon('23:00');
        $deHoy = $this->salon('00:00');
        $actual = $this->salon('01:00');

        Cache::put(EnviarRecordatorios::CACHE_ULTIMA_HORA, '2099-06-09 22:00');
        $this->correrA('2099-06-10 01:00:00');

        $this->assertFalse($this->enviado($deAyer));
        $this->assertTrue($this->enviado($deHoy));
        $this->assertTrue($this->enviado($actual));
    }

    public function test_correr_dos_veces_no_duplica_los_recordatorios(): void
    {
        Carbon::setTestNow('2099-06-10 09:00:00');
        $perdida = $this->salon('10:00');
        $actual = $this->salon('11:00');

        $this->correrA('2099-06-10 09:00:00');
        $this->correrA('2099-06-10 11:00:00');
        $this->correrA('2099-06-10 11:00:00');
        $this->correrA('2099-06-10 12:00:00');

        foreach ([$perdida, $actual] as $turno) {
            $this->assertSame(1, WhatsappMensaje::where('turno_id', $turno->id)->where('tipo', 'recordatorio')->count());
        }
        Http::assertSentCount(2);
    }

    public function test_un_turno_agendado_despues_de_la_hora_del_salon_no_recibe_recordatorio_en_la_corrida_siguiente(): void
    {
        // Sin horas salteadas (latido al día) no hay recuperación: la corrida
        // de las 11:00 no mira la hora de las 10:00 de un salón ya procesado.
        Carbon::setTestNow('2099-06-10 10:00:00');
        $this->salon('10:00');
        $this->correrA('2099-06-10 10:00:00');

        $tardio = Turno::create([
            'user_id' => User::where('hora_recordatorio', '10:00')->value('id'),
            'cliente_id' => Cliente::first()->id,
            'fecha_hora' => Carbon::tomorrow()->setTime(17, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        $this->correrA('2099-06-10 11:00:00');

        $this->assertFalse($this->enviado($tardio));
    }
}

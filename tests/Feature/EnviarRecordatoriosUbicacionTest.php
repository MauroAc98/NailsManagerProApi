<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// §Slice B: misma derivación tieneUbicacion() del job de confirmación,
// aplicada al comando de recordatorios (EnviarRecordatorios.php:135-142
// en el diseño).
class EnviarRecordatoriosUbicacionTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurnoManana(User $user): Turno
    {
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

    public function test_con_coordenadas_usa_la_plantilla_mapa_y_agrega_el_header(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.RMAPA1']]], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
            'telefono' => '3765000000',
            'latitud' => -27.4692,
            'longitud' => -58.8306,
        ]);

        $this->crearTurnoManana($user);

        $this->artisan('recordatorios:enviar');

        Http::assertSent(function ($request) {
            $componentes = $request->data()['template']['components'];

            return $request->data()['template']['name'] === 'recordatorio_turno_mapa'
                && count($componentes) === 2
                && $componentes[0]['type'] === 'header'
                && $componentes[0]['parameters'][0]['location']['latitude'] === '-27.4692';
        });
    }

    public function test_sin_coordenadas_usa_la_plantilla_legacy_y_no_agrega_header(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.RLEGACY1']]], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
            'telefono' => '3765000000',
            'latitud' => null,
            'longitud' => null,
        ]);

        $this->crearTurnoManana($user);

        $this->artisan('recordatorios:enviar');

        Http::assertSent(function ($request) {
            $componentes = $request->data()['template']['components'];

            return $request->data()['template']['name'] === 'recordatorio_turno'
                && count($componentes) === 1
                && $componentes[0]['type'] === 'body';
        });
    }
}

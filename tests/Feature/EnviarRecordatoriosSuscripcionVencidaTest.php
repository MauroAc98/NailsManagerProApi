<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Subscription;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnviarRecordatoriosSuscripcionVencidaTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurnoDeManana(User $user): Turno
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

    public function test_omite_recordatorios_automaticos_cuando_la_suscripcion_esta_vencida(): void
    {
        Http::fake();

        $user = User::factory()->create([
            'is_exempt' => false,
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
            'telefono' => '+543765111111',
        ]);
        Subscription::create(['user_id' => $user->id, 'ends_at' => now()->subDay(), 'status' => 'VENCIDO']);

        $this->crearTurnoDeManana($user);

        $this->artisan('recordatorios:enviar');

        Http::assertNothingSent();
    }

    public function test_omite_recordatorios_automaticos_sin_ninguna_suscripcion_cargada(): void
    {
        Http::fake();

        $user = User::factory()->create([
            'is_exempt' => false,
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
            'telefono' => '+543765111111',
        ]);

        $this->crearTurnoDeManana($user);

        $this->artisan('recordatorios:enviar');

        Http::assertNothingSent();
    }

    public function test_envia_recordatorios_con_suscripcion_vigente_y_cuenta_no_exenta(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TEST999']],
            ], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => false,
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
            'telefono' => '+543765111111',
            'direccion' => 'San Martin 123',
        ]);
        Subscription::create(['user_id' => $user->id, 'ends_at' => now()->addDays(10), 'status' => 'ACTIVO']);

        $this->crearTurnoDeManana($user);

        $this->artisan('recordatorios:enviar');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_cuenta_exenta_sin_suscripcion_igual_recibe_recordatorios(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TEST998']],
            ], 200),
        ]);

        $user = User::factory()->create([
            'is_exempt' => true,
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
            'telefono' => '+543765111111',
            'direccion' => 'San Martin 123',
        ]);

        $this->crearTurnoDeManana($user);

        $this->artisan('recordatorios:enviar');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }
}

<?php

namespace Tests\Feature;

use App\Mail\RecordatoriosPendientesMail;
use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappEstadoHistorial;
use App\Models\WhatsappMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EnviarRecordatoriosRequiereEnvioManualTest extends TestCase
{
    use RefreshDatabase;

    private function crearUsuarioConEnvioManual(): User
    {
        $user = User::factory()->create([
            'is_exempt' => true,
            'recordatorio_automatico' => true,
            'hora_recordatorio' => now()->format('H:00'),
            'evolution_instance_name' => 'user_1',
            'whatsapp_estado' => 'conectado',
        ]);

        $historial = WhatsappEstadoHistorial::create([
            'user_id' => $user->id,
            'estado' => 'desconectado',
            'status_reason' => 401,
        ]);
        $historial->created_at = now()->subDay();
        $historial->save();

        return $user;
    }

    public function test_omite_recordatorios_automaticos_cuando_requiere_envio_manual(): void
    {
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']], 200),
            '*/message/sendText/*' => Http::response(['key' => ['id' => 'ABC123']], 200),
        ]);
        Mail::fake();

        $user = $this->crearUsuarioConEnvioManual();

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '3765252395',
        ]);

        Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addDay()->setTime(10, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        $this->artisan('recordatorios:enviar');

        Http::assertNothingSent();
    }

    public function test_manda_mail_de_recordatorios_pendientes_cuando_hay_turnos_manana(): void
    {
        Http::fake();
        Mail::fake();

        $user = $this->crearUsuarioConEnvioManual();

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '3765252395',
        ]);

        Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addDay()->setTime(10, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        $this->artisan('recordatorios:enviar');

        Mail::assertSent(
            RecordatoriosPendientesMail::class,
            fn ($mail) => $mail->hasTo($user->email) && $mail->cantidadTurnos === 1,
        );
    }

    public function test_no_manda_mail_cuando_no_hay_turnos_manana(): void
    {
        Http::fake();
        Mail::fake();

        $this->crearUsuarioConEnvioManual();

        $this->artisan('recordatorios:enviar');

        Mail::assertNothingSent();
    }

    public function test_el_conteo_del_mail_no_incluye_turnos_con_cliente_sin_telefono(): void
    {
        Http::fake();
        Mail::fake();

        $user = $this->crearUsuarioConEnvioManual();

        $clienteConTelefono = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '3765252395',
        ]);

        $clienteSinTelefono = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Beti',
            'apellido' => 'Diaz',
            'telefono' => '',
        ]);

        Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $clienteConTelefono->id,
            'fecha_hora' => now()->addDay()->setTime(10, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $clienteSinTelefono->id,
            'fecha_hora' => now()->addDay()->setTime(11, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        $this->artisan('recordatorios:enviar');

        Mail::assertSent(
            RecordatoriosPendientesMail::class,
            fn ($mail) => $mail->hasTo($user->email) && $mail->cantidadTurnos === 1,
        );
    }

    public function test_no_manda_mail_cuando_el_unico_turno_de_manana_no_tiene_cliente_con_telefono(): void
    {
        Http::fake();
        Mail::fake();

        $user = $this->crearUsuarioConEnvioManual();

        $clienteSinTelefono = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Beti',
            'apellido' => 'Diaz',
            'telefono' => '',
        ]);

        Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $clienteSinTelefono->id,
            'fecha_hora' => now()->addDay()->setTime(11, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        $this->artisan('recordatorios:enviar');

        Mail::assertNothingSent();
    }

    public function test_no_manda_mail_cuando_el_unico_turno_ya_tiene_recordatorio_gestionado(): void
    {
        Http::fake();
        Mail::fake();

        $user = $this->crearUsuarioConEnvioManual();

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
            'intentos' => 1,
            'ultimo_intento' => now(),
        ]);

        $this->artisan('recordatorios:enviar');

        Mail::assertNothingSent();
    }

    public function test_el_conteo_del_mail_no_incluye_turnos_con_recordatorio_ya_gestionado(): void
    {
        Http::fake();
        Mail::fake();

        $user = $this->crearUsuarioConEnvioManual();

        $clienteGestionado = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '3765252395',
        ]);

        $turnoGestionado = Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $clienteGestionado->id,
            'fecha_hora' => now()->addDay()->setTime(10, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        WhatsappMensaje::create([
            'user_id' => $user->id,
            'turno_id' => $turnoGestionado->id,
            'numero' => '3765252395',
            'mensaje' => '',
            'tipo' => 'recordatorio',
            'status' => 'manual',
            'intentos' => 1,
            'ultimo_intento' => now(),
        ]);

        $clientePendiente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Beti',
            'apellido' => 'Diaz',
            'telefono' => '3765252396',
        ]);

        Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $clientePendiente->id,
            'fecha_hora' => now()->addDay()->setTime(11, 0),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        $this->artisan('recordatorios:enviar');

        Mail::assertSent(
            RecordatoriosPendientesMail::class,
            fn ($mail) => $mail->hasTo($user->email) && $mail->cantidadTurnos === 1,
        );
    }
}

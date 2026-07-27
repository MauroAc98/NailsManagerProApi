<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnoHorarioMultiAgendaTest extends TestCase
{
    use RefreshDatabase;

    private function crearCuentaConDosProfesionales(): array
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $jefa = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $empleada = Profesional::create(['user_id' => $user->id, 'nombre' => 'Empleada', 'activo' => true]);

        foreach (['09:00:00', '18:00:00'] as $hora) {
            SlotDisponible::create(['user_id' => $user->id, 'profesional_id' => $jefa->id, 'hora' => $hora, 'activo' => true]);
        }

        foreach (['10:00:00', '15:00:00'] as $hora) {
            SlotDisponible::create(['user_id' => $user->id, 'profesional_id' => $empleada->id, 'hora' => $hora, 'activo' => true]);
        }

        $cliente = Cliente::create(['user_id' => $user->id, 'nombre' => 'Cliente Test', 'telefono' => '3765252395']);
        $servicio = Servicio::create([
            'user_id' => $user->id,
            'nombre' => 'Manicura',
            'duracion_minutos' => 30,
            'precio' => 1000,
            'activo' => true,
        ]);
        $jefa->servicios()->attach($servicio->id);
        $empleada->servicios()->attach($servicio->id);

        return [$user, $jefa, $empleada, $cliente, $servicio];
    }

    public function test_rechaza_turno_fuera_del_horario_propio_de_la_profesional_aunque_este_dentro_del_de_otra(): void
    {
        [$user, , $empleada, $cliente, $servicio] = $this->crearCuentaConDosProfesionales();

        $fecha = now()->addDay()->format('Y-m-d');

        // 17:00 está dentro del horario de la jefa (09-18) pero fuera del de
        // la empleada (10-15) — antes del fix, validarHorarioAtencion usaba
        // el rango de TODA la cuenta y esto se aceptaba igual.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/turnos', [
                'cliente_id' => $cliente->id,
                'servicio_ids' => [$servicio->id],
                'fecha_hora' => "{$fecha} 17:00:00",
                'profesional_id' => $empleada->id,
            ])
            ->assertStatus(422)
            ->assertJson(['message' => 'El horario de atención es de 10:00 a 15:00hs.']);
    }

    public function test_rechaza_turno_de_la_jefa_fuera_de_su_propio_horario(): void
    {
        [$user, $jefa, , $cliente, $servicio] = $this->crearCuentaConDosProfesionales();

        $fecha = now()->addDay()->format('Y-m-d');

        // 08:00 está antes del horario de la jefa (09-18).
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/turnos', [
                'cliente_id' => $cliente->id,
                'servicio_ids' => [$servicio->id],
                'fecha_hora' => "{$fecha} 08:00:00",
                'profesional_id' => $jefa->id,
            ])
            ->assertStatus(422)
            ->assertJson(['message' => 'El horario de atención es de 09:00 a 18:00hs.']);
    }
}

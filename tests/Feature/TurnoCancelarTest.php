<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnoCancelarTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurno(User $user, $fechaHora, string $estado = 'confirmado', int $duracion = 60): Turno
    {
        $profesional = Profesional::firstOrCreate(
            ['user_id' => $user->id, 'nombre' => 'Jefa'],
            ['activo' => true],
        );
        $cliente = Cliente::firstOrCreate(
            ['user_id' => $user->id, 'nombre' => 'Ana'],
            ['telefono' => '3765252395'],
        );

        return Turno::create([
            'user_id' => $user->id,
            'profesional_id' => $profesional->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => $fechaHora,
            'duracion_total_minutos' => $duracion,
            'estado' => $estado,
            'origen' => 'app',
        ]);
    }

    private function cancelar(User $user, Turno $turno)
    {
        return $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/turnos/{$turno->id}", ['motivo_cancelacion' => 'La clienta no vino']);
    }

    // Un turno "en curso" (ya empezó, todavía no terminó) tiene que poder
    // cancelarse: es el caso de la clienta que nunca llegó y el turno se
    // arrancó solo. Antes se rechazaba porque la hora de INICIO ya pasó.
    public function test_permite_cancelar_un_turno_en_curso(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user, now()->subMinutes(20), 'confirmado', 60);

        $this->cancelar($user, $turno)->assertOk();

        $this->assertSame('cancelado', $turno->fresh()->estado);
    }

    public function test_rechaza_cancelar_un_turno_que_ya_termino(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user, now()->subHours(3), 'confirmado', 60);

        $this->cancelar($user, $turno)
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se pueden cancelar turnos que ya pasaron.');

        $this->assertSame('confirmado', $turno->fresh()->estado);
    }

    public function test_permite_cancelar_un_turno_futuro(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user, now()->addDay(), 'confirmado', 60);

        $this->cancelar($user, $turno)->assertOk();

        $this->assertSame('cancelado', $turno->fresh()->estado);
    }

    // Un turno finalizado antes de su hora de fin sigue "dentro" de su
    // duración, pero ya se atendió: no se cancela.
    public function test_rechaza_cancelar_un_turno_ya_finalizado_aunque_siga_dentro_de_su_duracion(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $turno = $this->crearTurno($user, now()->subMinutes(20), 'completado', 60);

        $this->cancelar($user, $turno)->assertStatus(422);

        $this->assertSame('completado', $turno->fresh()->estado);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editar un turno cancelado o completado reescribiria historial/ingresos
 * (los servicios del turno son la base del monto). Solo se editan confirmados.
 */
class TurnoUpdateSoloConfirmadosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Servicio $servicio;
    private Profesional $profesional;
    private Cliente $cliente;
    private Turno $turno;
    private string $fecha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_exempt' => true]);
        $this->profesional = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'activo' => true]);
        foreach (['09:00:00', '18:00:00'] as $hora) {
            SlotDisponible::create(['user_id' => $this->user->id, 'profesional_id' => $this->profesional->id, 'hora' => $hora, 'activo' => true]);
        }
        $this->servicio = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Mani', 'duracion_minutos' => 30, 'precio' => 1000, 'activo' => true]);
        $this->profesional->servicios()->attach($this->servicio->id);
        $this->cliente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Propia', 'telefono' => '3765252395']);

        $this->fecha = now()->addDay()->format('Y-m-d');
        $this->actingAs($this->user, 'sanctum')->postJson('/api/turnos', [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} 09:00:00",
            'profesional_id' => $this->profesional->id,
        ])->assertSuccessful();
        $this->turno = Turno::firstOrFail();
    }

    private function editar()
    {
        return $this->actingAs($this->user, 'sanctum')->putJson("/api/turnos/{$this->turno->id}", [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} 18:00:00",
            'profesional_id' => $this->profesional->id,
        ]);
    }

    public function test_un_turno_confirmado_se_puede_editar(): void
    {
        $this->editar()->assertSuccessful();
    }

    public function test_un_turno_cancelado_no_se_puede_editar(): void
    {
        $this->turno->update(['estado' => 'cancelado']);

        $this->editar()->assertStatus(422);

        $this->assertSame("{$this->fecha} 09:00:00", $this->turno->fresh()->fecha_hora->format('Y-m-d H:i:s'));
    }

    public function test_un_turno_completado_no_se_puede_editar(): void
    {
        $this->turno->update(['estado' => 'completado']);

        $this->editar()->assertStatus(422);

        $this->assertSame("{$this->fecha} 09:00:00", $this->turno->fresh()->fecha_hora->format('Y-m-d H:i:s'));
    }
}

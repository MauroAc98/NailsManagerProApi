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
 * La nota que el cliente escribe al reservar online ("Contanos tu idea") vive
 * en turnos.notas. La pantalla de editar turno no manda ese campo, asi que un
 * PUT sin la clave no debe borrarla; solo se cambia o se vacia si viene.
 */
class TurnoUpdateConservaNotasTest extends TestCase
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
        $this->turno->update(['notas' => 'Quiero algo minimalista']);
    }

    private function editar(array $extra = [], string $hora = '18:00:00')
    {
        return $this->actingAs($this->user, 'sanctum')->putJson("/api/turnos/{$this->turno->id}", [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} {$hora}",
            'profesional_id' => $this->profesional->id,
        ] + $extra);
    }

    public function test_editar_sin_mandar_notas_conserva_la_nota_del_cliente(): void
    {
        $this->editar()->assertSuccessful();

        $this->assertSame('Quiero algo minimalista', $this->turno->fresh()->notas);
    }

    public function test_si_se_manda_notas_se_reemplaza(): void
    {
        $this->editar(['notas' => 'Cambio de idea'])->assertSuccessful();

        $this->assertSame('Cambio de idea', $this->turno->fresh()->notas);
    }

    public function test_si_se_manda_notas_en_null_se_vacia(): void
    {
        $this->editar(['notas' => null])->assertSuccessful();

        $this->assertNull($this->turno->fresh()->notas);
    }
}

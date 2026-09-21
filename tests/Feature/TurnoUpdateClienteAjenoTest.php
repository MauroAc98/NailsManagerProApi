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
 * Aislamiento entre negocios en PUT /api/turnos/{id}: el cliente_id del body
 * debe pertenecer al negocio autenticado. Antes se validaba con un exists
 * global, asi que un negocio podia editar su turno apuntandolo a un cliente
 * ajeno y leer nombre/telefono de ese cliente en la respuesta.
 */
class TurnoUpdateClienteAjenoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Cliente $cliente;
    private Cliente $otroClienteMio;
    private Cliente $clienteAjeno;
    private Servicio $servicio;
    private Profesional $profesional;
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
        $this->otroClienteMio = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Otra Propia', 'telefono' => '3765252396']);

        $otroNegocio = User::factory()->create(['is_exempt' => true]);
        $this->clienteAjeno = Cliente::create(['user_id' => $otroNegocio->id, 'nombre' => 'Secreta', 'apellido' => 'Ajena', 'telefono' => '3765999999']);

        $this->fecha = now()->addDay()->format('Y-m-d');
        $respuesta = $this->actingAs($this->user, 'sanctum')->postJson('/api/turnos', [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} 09:00:00",
            'profesional_id' => $this->profesional->id,
        ]);
        $respuesta->assertSuccessful();
        $this->turno = Turno::firstOrFail();
    }

    private function editar(int $clienteId)
    {
        return $this->actingAs($this->user, 'sanctum')->putJson("/api/turnos/{$this->turno->id}", [
            'cliente_id' => $clienteId,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} 09:00:00",
            'profesional_id' => $this->profesional->id,
        ]);
    }

    public function test_no_se_puede_reasignar_el_turno_a_un_cliente_de_otro_negocio(): void
    {
        $this->editar($this->clienteAjeno->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('cliente_id');

        $this->assertSame($this->cliente->id, $this->turno->fresh()->cliente_id);
    }

    public function test_la_respuesta_nunca_filtra_datos_del_cliente_ajeno(): void
    {
        $respuesta = $this->editar($this->clienteAjeno->id);

        $this->assertStringNotContainsString('Secreta', $respuesta->getContent());
        $this->assertStringNotContainsString('3765999999', $respuesta->getContent());
    }

    public function test_si_se_puede_reasignar_a_otro_cliente_del_mismo_negocio(): void
    {
        $this->editar($this->otroClienteMio->id)->assertSuccessful();

        $this->assertSame($this->otroClienteMio->id, $this->turno->fresh()->cliente_id);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\User;
use App\Services\Reservas\SlotLock;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnoManualLockYProfesionalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profesional $ana;
    private Profesional $bea;
    private Cliente $cliente;
    private Servicio $servicio;
    private string $fecha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_exempt' => true]);
        // Ana es la más antigua: es la que resuelve el default cuando no viene profesional_id.
        $this->ana = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'activo' => true]);
        $this->bea = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Bea', 'activo' => true]);
        foreach ([$this->ana, $this->bea] as $prof) {
            foreach (['09:00:00', '10:00:00', '18:00:00'] as $hora) {
                SlotDisponible::create(['user_id' => $this->user->id, 'profesional_id' => $prof->id, 'hora' => $hora, 'activo' => true]);
            }
        }
        $this->cliente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Cli', 'telefono' => '3765252395']);
        $this->servicio = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Mani', 'duracion_minutos' => 30, 'precio' => 1000, 'activo' => true]);
        $this->ana->servicios()->attach($this->servicio->id);
        $this->bea->servicios()->attach($this->servicio->id);
        $this->fecha = now()->addDay()->format('Y-m-d');
    }

    /** SlotLock que registra para qué profesionales se pidió lock. */
    private function espiarLock(): object
    {
        $espia = new class extends SlotLock {
            /** @var int[] */
            public array $profesionalIds = [];

            public function conLock(int $profesionalId, Closure $fn): mixed
            {
                $this->profesionalIds[] = $profesionalId;

                return parent::conLock($profesionalId, $fn);
            }
        };
        $this->app->instance(SlotLock::class, $espia);

        return $espia;
    }

    private function turnoDe(Profesional $prof, string $hora): Turno
    {
        $turno = Turno::create([
            'user_id' => $this->user->id,
            'profesional_id' => $prof->id,
            'cliente_id' => $this->cliente->id,
            'fecha_hora' => "{$this->fecha} {$hora}",
            'duracion_total_minutos' => 30,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
        $turno->servicios()->attach($this->servicio->id);

        return $turno;
    }

    public function test_crear_un_turno_manual_serializa_con_el_lock_de_la_profesional(): void
    {
        $espia = $this->espiarLock();

        $this->actingAs($this->user, 'sanctum')->postJson('/api/turnos', [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} 10:00:00",
            'profesional_id' => $this->bea->id,
        ])->assertCreated();

        $this->assertSame([$this->bea->id], $espia->profesionalIds);
    }

    public function test_editar_un_turno_manual_serializa_con_el_lock_de_la_profesional(): void
    {
        $turno = $this->turnoDe($this->bea, '09:00:00');
        $espia = $this->espiarLock();

        $this->actingAs($this->user, 'sanctum')->putJson("/api/turnos/{$turno->id}", [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} 10:00:00",
            'profesional_id' => $this->bea->id,
        ])->assertOk();

        $this->assertSame([$this->bea->id], $espia->profesionalIds);
    }

    public function test_un_choque_detectado_dentro_del_lock_no_crea_el_turno(): void
    {
        $this->turnoDe($this->bea, '10:00:00');
        $this->espiarLock();

        $this->actingAs($this->user, 'sanctum')->postJson('/api/turnos', [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} 10:00:00",
            'profesional_id' => $this->bea->id,
        ])->assertStatus(422);

        $this->assertSame(1, Turno::where('profesional_id', $this->bea->id)->count());
    }

    public function test_editar_sin_profesional_id_conserva_la_profesional_del_turno(): void
    {
        $turno = $this->turnoDe($this->bea, '09:00:00');

        $this->actingAs($this->user, 'sanctum')->putJson("/api/turnos/{$turno->id}", [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} 10:00:00",
        ])->assertOk();

        $this->assertSame($this->bea->id, $turno->fresh()->profesional_id);
    }

    public function test_editar_con_profesional_id_explicito_si_cambia_de_profesional(): void
    {
        $turno = $this->turnoDe($this->bea, '09:00:00');

        $this->actingAs($this->user, 'sanctum')->putJson("/api/turnos/{$turno->id}", [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} 10:00:00",
            'profesional_id' => $this->ana->id,
        ])->assertOk();

        $this->assertSame($this->ana->id, $turno->fresh()->profesional_id);
    }
}

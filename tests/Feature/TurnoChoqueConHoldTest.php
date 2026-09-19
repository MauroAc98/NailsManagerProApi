<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnoChoqueConHoldTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAJE_HOLD = 'Una clienta está reservando ese horario en este momento. Probá en unos minutos o elegí otro horario.';

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
        $this->ana = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'activo' => true]);
        $this->bea = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Bea', 'activo' => true]);
        foreach ([$this->ana, $this->bea] as $prof) {
            foreach (['09:00:00', '18:00:00'] as $hora) {
                SlotDisponible::create(['user_id' => $this->user->id, 'profesional_id' => $prof->id, 'hora' => $hora, 'activo' => true]);
            }
        }
        $this->cliente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Cli', 'telefono' => '3765252395']);
        $this->servicio = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Mani', 'duracion_minutos' => 30, 'precio' => 1000, 'activo' => true]);
        $this->ana->servicios()->attach($this->servicio->id);
        $this->bea->servicios()->attach($this->servicio->id);
        $this->fecha = now()->addDay()->format('Y-m-d');
    }

    private function hold(Profesional $prof, string $hora, int $duracion = 60, int $expiraDesdeAhora = 600, string $estado = 'held'): ReservaWeb
    {
        return ReservaWeb::create([
            'user_id' => $this->user->id,
            'profesional_id' => $prof->id,
            'public_token' => ReservaWeb::generarToken(),
            'servicio_ids' => [$this->servicio->id],
            'fecha' => $this->fecha,
            'slot_hora' => $hora,
            'duracion_total_minutos' => $duracion,
            'estado' => $estado,
            'expira_en' => now()->timestamp + $expiraDesdeAhora,
        ]);
    }

    private function crear(Profesional $prof, string $hora, ?Cliente $cliente = null)
    {
        return $this->actingAs($this->user, 'sanctum')->postJson('/api/turnos', [
            'cliente_id' => ($cliente ?? $this->cliente)->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} {$hora}",
            'profesional_id' => $prof->id,
        ]);
    }

    public function test_la_duena_no_puede_agendar_encima_de_un_hold_vivo(): void
    {
        $this->hold($this->ana, '10:00:00', 60);

        $this->crear($this->ana, '10:30:00')
            ->assertStatus(422)
            ->assertJson(['message' => self::MENSAJE_HOLD, 'code' => 'slot_held']);

        $this->assertSame(0, Turno::count());
    }

    public function test_tambien_bloquea_un_hold_en_pending_payment(): void
    {
        $this->hold($this->ana, '10:00:00', 60, 600, 'pending_payment');

        $this->crear($this->ana, '10:00:00')->assertStatus(422)->assertJsonPath('code', 'slot_held');
    }

    public function test_un_hold_vencido_no_bloquea_aunque_siga_held(): void
    {
        $this->hold($this->ana, '10:00:00', 60, -1);

        $this->crear($this->ana, '10:00:00')->assertCreated();
    }

    public function test_un_hold_de_otra_profesional_no_bloquea(): void
    {
        $this->hold($this->bea, '10:00:00', 60);

        $this->crear($this->ana, '10:00:00')->assertCreated();
    }

    public function test_los_intervalos_adyacentes_al_hold_no_chocan(): void
    {
        $this->hold($this->ana, '10:00:00', 60); // [10:00, 11:00)

        $this->crear($this->ana, '11:00:00')->assertCreated();
        $otra = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Otra', 'telefono' => '3765252396']);
        $this->crear($this->ana, '09:30:00', $otra)->assertCreated(); // [09:30, 10:00)
    }

    public function test_holds_no_bloqueantes_no_chocan(): void
    {
        $this->hold($this->ana, '10:00:00', 60, 600, 'cancelled');
        $this->hold($this->ana, '10:00:00', 60, 600, 'expired');

        $this->crear($this->ana, '10:00:00')->assertCreated();
    }

    public function test_editar_un_turno_hacia_un_hold_vivo_tambien_choca(): void
    {
        $turno = Turno::create([
            'user_id' => $this->user->id, 'profesional_id' => $this->ana->id, 'cliente_id' => $this->cliente->id,
            'fecha_hora' => "{$this->fecha} 14:00:00", 'duracion_total_minutos' => 30, 'estado' => 'confirmado', 'origen' => 'app',
        ]);
        $turno->servicios()->attach($this->servicio->id);
        $this->hold($this->ana, '10:00:00', 60);

        $this->actingAs($this->user, 'sanctum')->putJson("/api/turnos/{$turno->id}", [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} 10:00:00",
            'profesional_id' => $this->ana->id,
        ])->assertStatus(422)->assertJson(['message' => self::MENSAJE_HOLD, 'code' => 'slot_held']);

        // Reprogramar a un horario libre sigue funcionando.
        $this->actingAs($this->user, 'sanctum')->putJson("/api/turnos/{$turno->id}", [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} 15:00:00",
            'profesional_id' => $this->ana->id,
        ])->assertOk();
    }

    public function test_el_choque_con_un_turno_real_conserva_su_mensaje_y_forma(): void
    {
        $this->crear($this->ana, '10:00:00')->assertCreated();
        $otra = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Otra', 'telefono' => '3765252396']);

        $resp = $this->crear($this->ana, '10:00:00', $otra)->assertStatus(422);

        $this->assertStringContainsString('cae dentro del turno de', $resp->json('message'));
        $this->assertArrayNotHasKey('code', $resp->json());
    }
}

<?php

namespace Tests\Feature;

use App\Models\BloqueoAgenda;
use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * dias_atencion y bloqueos_agenda son un filtro de DISPONIBILIDAD (nuevos
 * turnos ofrecidos), no una regla de choque. La agenda propia de la
 * profesional (TurnoController) queda intencionalmente sin tocar — el
 * aviso "este dia no es laborable / hay un bloqueo" se resuelve 100%
 * client-side (ver design). Este test prueba esa garantia: store/update
 * siguen devolviendo 201/200 sin un nuevo error duro.
 */
class TurnoDiasAtencionYBloqueosRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profesional $ana;
    private Cliente $cliente;
    private Servicio $servicio;
    private string $fecha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_exempt' => true]);
        $this->ana = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'activo' => true]);
        foreach (['09:00:00', '18:00:00'] as $hora) {
            SlotDisponible::create(['user_id' => $this->user->id, 'profesional_id' => $this->ana->id, 'hora' => $hora, 'activo' => true]);
        }
        $this->cliente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Cli', 'telefono' => '3765252395']);
        $this->servicio = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Mani', 'duracion_minutos' => 30, 'precio' => 1000, 'activo' => true]);
        $this->ana->servicios()->attach($this->servicio->id);
        // Un dia bien futuro, cuyo dayOfWeek se usa mas abajo para "no laborable".
        $this->fecha = '2099-06-10';
    }

    public function test_store_en_un_dia_de_la_semana_no_laborable_para_la_profesional_igual_crea_el_turno(): void
    {
        $diaDeLaSemana = Carbon::parse($this->fecha)->dayOfWeek;
        $this->ana->update(['dias_atencion' => array_values(array_diff(range(0, 6), [$diaDeLaSemana]))]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/turnos', [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} 10:00:00",
            'profesional_id' => $this->ana->id,
        ]);

        $response->assertCreated();
        $this->assertSame(1, Turno::count());
    }

    public function test_store_sobre_una_fecha_bloqueada_igual_crea_el_turno(): void
    {
        BloqueoAgenda::create(['user_id' => $this->user->id, 'profesional_id' => $this->ana->id, 'fecha' => $this->fecha]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/turnos', [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} 10:00:00",
            'profesional_id' => $this->ana->id,
        ]);

        $response->assertCreated();
        $this->assertSame(1, Turno::count());
    }

    public function test_update_hacia_un_dia_no_laborable_o_bloqueado_igual_actualiza_el_turno(): void
    {
        $turno = Turno::create([
            'user_id' => $this->user->id,
            'profesional_id' => $this->ana->id,
            'cliente_id' => $this->cliente->id,
            'fecha_hora' => now()->addDay()->format('Y-m-d') . ' 09:00:00',
            'duracion_total_minutos' => 30,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
        $turno->servicios()->attach($this->servicio->id);

        $diaDeLaSemana = Carbon::parse($this->fecha)->dayOfWeek;
        $this->ana->update(['dias_atencion' => array_values(array_diff(range(0, 6), [$diaDeLaSemana]))]);
        BloqueoAgenda::create(['user_id' => $this->user->id, 'profesional_id' => $this->ana->id, 'fecha' => $this->fecha, 'hora_desde' => '09:00', 'hora_hasta' => '12:00']);

        $response = $this->actingAs($this->user, 'sanctum')->putJson("/api/turnos/{$turno->id}", [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => "{$this->fecha} 10:00:00",
            'profesional_id' => $this->ana->id,
        ]);

        $response->assertOk();
        $this->assertSame("{$this->fecha} 10:00:00", $turno->fresh()->getRawOriginal('fecha_hora'));
    }
}

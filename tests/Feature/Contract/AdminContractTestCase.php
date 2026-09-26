<?php

namespace Tests\Feature\Contract;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssertsContractShape;
use Tests\TestCase;

/**
 * Shared fixtures + recorded shapes for the admin (authenticated) endpoints
 * that return raw Eloquent models. Shapes were recorded from the unchanged
 * production code; see AssertsContractShape for the additive-only semantics.
 */
abstract class AdminContractTestCase extends TestCase
{
    use AssertsContractShape;
    use RefreshDatabase;

    protected const CLIENTE = [
        'id' => 'int', 'user_id' => 'int', 'telefono' => 'string', 'created_at' => 'string',
        'updated_at' => 'string', 'nombre' => 'string', 'apellido' => 'string|null',
        'activo' => 'bool', 'whatsapp_opt_out' => 'bool',
    ];

    // Servicio row as returned by index/show/update (and nested in a turno).
    protected const SERVICIO = [
        'id' => 'int', 'user_id' => 'int', 'nombre' => 'string', 'duracion_minutos' => 'int',
        'precio' => 'string', 'activo' => 'bool', 'created_at' => 'string', 'updated_at' => 'string',
        'es_promo' => 'bool', 'orden' => 'int', 'categoria_id' => 'int|null',
    ];

    // Turno keys present on every turno response, including a freshly created one.
    protected const TURNO_CORE = [
        'id' => 'int', 'user_id' => 'int', 'cliente_id' => 'int', 'profesional_id' => 'int',
        'reserva_web_id' => 'int|null', 'fecha_hora' => 'string', 'duracion_total_minutos' => 'int',
        'estado' => 'string', 'origen' => 'string', 'notas' => 'string|null',
        'created_at' => 'string', 'updated_at' => 'string',
    ];

    // A freshly created Turno (POST /turnos) does not carry these two yet.
    protected const TURNO_CANCELACION = ['motivo_cancelacion' => 'string|null', 'cancelado_en' => 'string|null'];

    protected User $user;
    protected Profesional $ana;
    protected Cliente $cliente;
    protected Servicio $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // POST /turnos dispatches the WhatsApp confirmation job

        $this->user = User::factory()->create(['is_exempt' => true]);
        $this->ana = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'activo' => true]);
        foreach (['09:00:00', '18:00:00'] as $hora) {
            SlotDisponible::create(['user_id' => $this->user->id, 'profesional_id' => $this->ana->id, 'hora' => $hora, 'activo' => true]);
        }
        $this->cliente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Cli', 'telefono' => '3765252395']);
        $this->servicio = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Mani', 'duracion_minutos' => 30, 'precio' => 1000, 'activo' => true]);
        $this->ana->servicios()->attach($this->servicio->id);
    }

    /** Prod-like actor: the authenticated user is loaded from the DB, with every column. */
    protected function admin(): static
    {
        return $this->actingAs($this->user->fresh(), 'sanctum');
    }

    protected function serviciosDeTurnoShape(): array
    {
        return ['*' => array_merge(self::SERVICIO, [
            'pivot' => ['turno_id' => 'int', 'servicio_id' => 'int', 'precio' => 'number|string|null'],
        ])];
    }

    protected function turnoShape(array $extra = []): array
    {
        return array_merge(self::TURNO_CORE, self::TURNO_CANCELACION, [
            'cliente' => self::CLIENTE,
            'servicios' => $this->serviciosDeTurnoShape(),
        ], $extra);
    }

    protected function crearTurno(string $estado = 'confirmado', string $fechaHora = '2099-06-10 10:00:00'): Turno
    {
        $turno = Turno::create([
            'user_id' => $this->user->id,
            'profesional_id' => $this->ana->id,
            'cliente_id' => $this->cliente->id,
            'fecha_hora' => $fechaHora,
            'duracion_total_minutos' => 30,
            'estado' => $estado,
            'origen' => 'app',
            'notas' => 'nota',
        ]);
        $turno->servicios()->attach($this->servicio->id);

        return $turno;
    }
}

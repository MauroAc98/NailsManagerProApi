<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Filtro por mes de GET /api/turnos?mes= y GET /api/turnos/marcas?mes=.
// Antes usaba TO_CHAR(fecha_hora, 'YYYY-MM') = ?, que no existe en SQLite (por
// eso nunca tuvo tests) y en Postgres impide usar el índice de fecha_hora. Se
// reescribió como rango [primer día 00:00, primer día del mes siguiente 00:00).
class TurnoFiltroPorMesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profesional $profesional;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_exempt' => true]);
        $this->profesional = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $this->cliente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'telefono' => '3765252395']);
    }

    private function turno(string $fechaHora, ?User $user = null, string $estado = 'confirmado'): Turno
    {
        $user ??= $this->user;
        $profesional = $user->is($this->user)
            ? $this->profesional
            : Profesional::create(['user_id' => $user->id, 'nombre' => 'Otra', 'activo' => true]);
        $cliente = $user->is($this->user)
            ? $this->cliente
            : Cliente::create(['user_id' => $user->id, 'nombre' => 'Bea', 'telefono' => '3765252396']);

        return Turno::create([
            'user_id' => $user->id,
            'profesional_id' => $profesional->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => $fechaHora,
            'duracion_total_minutos' => 60,
            'estado' => $estado,
            'origen' => 'app',
        ]);
    }

    /** @return array<int, int> */
    private function idsDelMes(string $mes): array
    {
        return collect(
            $this->actingAs($this->user, 'sanctum')->getJson("/api/turnos?mes={$mes}")->assertOk()->json()
        )->pluck('id')->all();
    }

    public function test_index_trae_solo_los_turnos_del_mes_incluidos_los_bordes(): void
    {
        $primero = $this->turno('2026-10-01 00:00:00');
        $medio = $this->turno('2026-10-15 10:00:00');
        $ultimo = $this->turno('2026-10-31 23:59:59');
        $mesAnterior = $this->turno('2026-09-30 23:59:59');
        $mesSiguiente = $this->turno('2026-11-01 00:00:00');

        $ids = $this->idsDelMes('2026-10');

        $this->assertEqualsCanonicalizing([$primero->id, $medio->id, $ultimo->id], $ids);
        $this->assertNotContains($mesAnterior->id, $ids);
        $this->assertNotContains($mesSiguiente->id, $ids);
    }

    public function test_diciembre_no_se_mezcla_con_enero_del_anio_siguiente(): void
    {
        $diciembre = $this->turno('2026-12-31 23:59:59');
        $enero = $this->turno('2027-01-01 00:00:00');

        $this->assertSame([$diciembre->id], $this->idsDelMes('2026-12'));
        $this->assertSame([$enero->id], $this->idsDelMes('2027-01'));
    }

    public function test_febrero_bisiesto_llega_hasta_el_29(): void
    {
        $dia29 = $this->turno('2028-02-29 18:00:00');
        $marzo = $this->turno('2028-03-01 00:00:00');

        $this->assertSame([$dia29->id], $this->idsDelMes('2028-02'));
        $this->assertSame([$marzo->id], $this->idsDelMes('2028-03'));
    }

    #[DataProvider('mesesInvalidos')]
    public function test_un_mes_mal_formado_devuelve_vacio_como_antes(string $mes): void
    {
        $this->turno('2026-10-15 10:00:00');

        $this->assertSame([], $this->idsDelMes($mes));
    }

    /** @return array<string, array{0: string}> */
    public static function mesesInvalidos(): array
    {
        return [
            'mes 13' => ['2026-13'],
            'mes 00' => ['2026-00'],
            'sin cero' => ['2026-1'],
            'texto' => ['octubre'],
            'fecha completa' => ['2026-10-15'],
        ];
    }

    public function test_index_sigue_ocultando_cancelados_y_otras_cuentas(): void
    {
        $propio = $this->turno('2026-10-10 10:00:00');
        $this->turno('2026-10-11 10:00:00', estado: 'cancelado');
        $otro = User::factory()->create(['is_exempt' => true]);
        $this->turno('2026-10-12 10:00:00', $otro);

        $this->assertSame([$propio->id], $this->idsDelMes('2026-10'));
    }

    public function test_marcas_cuenta_por_dia_solo_del_mes_pedido(): void
    {
        $this->turno('2026-10-01 00:00:00');
        $this->turno('2026-10-01 15:00:00');
        $this->turno('2026-10-31 23:59:59');
        $this->turno('2026-09-30 23:59:59');
        $this->turno('2026-11-01 00:00:00');
        $this->turno('2026-10-20 10:00:00', estado: 'cancelado');

        $marcas = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/turnos/marcas?mes=2026-10')
            ->assertOk()
            ->json();

        $this->assertSame(['2026-10-01', '2026-10-31'], array_keys($marcas));
        $this->assertSame(2, $marcas['2026-10-01']['cantidad']);
        $this->assertSame(1, $marcas['2026-10-31']['cantidad']);
    }

    public function test_marcas_no_cuenta_turnos_de_otra_cuenta(): void
    {
        $otro = User::factory()->create(['is_exempt' => true]);
        $this->turno('2026-10-12 10:00:00', $otro);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/turnos/marcas?mes=2026-10')
            ->assertOk()
            ->assertExactJson([]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Filtros por día (?fecha=) y por rango (?desde=&hasta=) de GET /api/turnos, que
// usan Turno::delaFecha() y Turno::delRango(). Antes eran whereDate(), que en
// Postgres compila a fecha_hora::date = ? y no puede usar el índice de
// fecha_hora; ahora son rangos [día 00:00, día siguiente 00:00).
class TurnoFiltroPorDiaYRangoTest extends TestCase
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

    private function turno(string $fechaHora, string $estado = 'confirmado'): Turno
    {
        return Turno::create([
            'user_id' => $this->user->id,
            'profesional_id' => $this->profesional->id,
            'cliente_id' => $this->cliente->id,
            'fecha_hora' => $fechaHora,
            'duracion_total_minutos' => 60,
            'estado' => $estado,
            'origen' => 'app',
        ]);
    }

    /** @return array<int, int> */
    private function ids(string $query): array
    {
        return collect(
            $this->actingAs($this->user, 'sanctum')->getJson("/api/turnos?{$query}")->assertOk()->json()
        )->pluck('id')->all();
    }

    // ── ?fecha= ────────────────────────────────────────────────────

    public function test_un_dia_incluye_su_primer_y_ultimo_segundo_y_nada_de_los_vecinos(): void
    {
        $inicio = $this->turno('2026-10-13 00:00:00');
        $medio = $this->turno('2026-10-13 12:30:00');
        $fin = $this->turno('2026-10-13 23:59:59');
        $ayer = $this->turno('2026-10-12 23:59:59');
        $manana = $this->turno('2026-10-14 00:00:00');

        $ids = $this->ids('fecha=2026-10-13');

        $this->assertEqualsCanonicalizing([$inicio->id, $medio->id, $fin->id], $ids);
        $this->assertNotContains($ayer->id, $ids);
        $this->assertNotContains($manana->id, $ids);
    }

    public function test_el_ultimo_dia_del_anio_no_se_mezcla_con_el_siguiente(): void
    {
        $nochevieja = $this->turno('2026-12-31 23:30:00');
        $anioNuevo = $this->turno('2027-01-01 00:00:00');

        $this->assertSame([$nochevieja->id], $this->ids('fecha=2026-12-31'));
        $this->assertSame([$anioNuevo->id], $this->ids('fecha=2027-01-01'));
    }

    public function test_el_29_de_febrero_de_un_anio_bisiesto(): void
    {
        $dia29 = $this->turno('2028-02-29 18:00:00');
        $this->turno('2028-03-01 00:00:00');

        $this->assertSame([$dia29->id], $this->ids('fecha=2028-02-29'));
    }

    public function test_una_fecha_con_hora_se_trunca_al_dia_como_en_postgres(): void
    {
        // En Postgres whereDate castea el parámetro a date, así que "2026-10-13 15:00:00"
        // equivale al día 2026-10-13 completo.
        $manana = $this->turno('2026-10-13 09:00:00');
        $this->turno('2026-10-14 09:00:00');

        $this->assertSame([$manana->id], $this->ids('fecha='.urlencode('2026-10-13 15:00:00')));
    }

    public function test_el_dia_sigue_ocultando_cancelados(): void
    {
        $vivo = $this->turno('2026-10-13 10:00:00');
        $this->turno('2026-10-13 11:00:00', 'cancelado');

        $this->assertSame([$vivo->id], $this->ids('fecha=2026-10-13'));
    }

    // ── ?desde=&hasta= ─────────────────────────────────────────────

    public function test_un_rango_incluye_todo_el_dia_de_hasta_y_nada_mas_alla(): void
    {
        $primero = $this->turno('2026-10-10 00:00:00');
        $medio = $this->turno('2026-10-11 15:00:00');
        $ultimo = $this->turno('2026-10-12 23:59:59');
        $antes = $this->turno('2026-10-09 23:59:59');
        $despues = $this->turno('2026-10-13 00:00:00');

        $ids = $this->ids('desde=2026-10-10&hasta=2026-10-12');

        $this->assertEqualsCanonicalizing([$primero->id, $medio->id, $ultimo->id], $ids);
        $this->assertNotContains($antes->id, $ids);
        $this->assertNotContains($despues->id, $ids);
    }

    public function test_un_rango_de_un_solo_dia(): void
    {
        $dentro = $this->turno('2026-10-10 20:00:00');
        $this->turno('2026-10-11 00:00:00');
        $this->turno('2026-10-09 23:59:59');

        $this->assertSame([$dentro->id], $this->ids('desde=2026-10-10&hasta=2026-10-10'));
    }

    public function test_un_rango_que_cruza_fin_de_mes_y_de_anio(): void
    {
        $a = $this->turno('2026-12-30 10:00:00');
        $b = $this->turno('2027-01-02 23:59:59');
        $this->turno('2027-01-03 00:00:00');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->ids('desde=2026-12-30&hasta=2027-01-02'));
    }
}

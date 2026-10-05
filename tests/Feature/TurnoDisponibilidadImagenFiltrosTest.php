<?php

namespace Tests\Feature;

use App\Models\BloqueoAgenda;
use App\Models\Profesional;
use App\Models\SlotDisponible;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GET /api/turnos/disponibilidad alimenta la imagen de horarios libres que el
 * salon publica: no debe mostrar como libre un dia que la profesional no
 * atiende ni un horario bloqueado. (La agenda propia sigue permitiendo cargar
 * turnos ahi: ese override es del dueno, no de la imagen.)
 */
class TurnoDisponibilidadImagenFiltrosTest extends TestCase
{
    use RefreshDatabase;

    private const FECHA = '2099-06-10';

    private User $user;
    private Profesional $ana;
    private Profesional $laura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_exempt' => true]);
        $this->ana = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'activo' => true]);
        $this->laura = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Laura', 'activo' => true]);
        foreach ([$this->ana, $this->laura] as $p) {
            foreach (['09:00:00', '18:00:00'] as $hora) {
                SlotDisponible::create(['user_id' => $this->user->id, 'profesional_id' => $p->id, 'hora' => $hora, 'activo' => true]);
            }
        }
    }

    /** @return array<string, bool> hora => libre */
    private function libres(Profesional $p): array
    {
        $dias = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/turnos/disponibilidad?'.http_build_query(['desde' => self::FECHA, 'hasta' => self::FECHA, 'profesional_id' => $p->id]))
            ->assertOk()->json();

        return collect($dias[0]['slots'])->pluck('libre', 'hora')->all();
    }

    public function test_sin_restricciones_los_slots_estan_libres(): void
    {
        $this->assertSame(['09hs' => true, '18hs' => true], $this->libres($this->ana));
    }

    public function test_un_dia_que_la_profesional_no_atiende_no_aparece_libre(): void
    {
        $dow = Carbon::parse(self::FECHA)->dayOfWeek;
        $this->ana->update(['dias_atencion' => array_values(array_diff(range(0, 6), [$dow]))]);

        $this->assertSame(['09hs' => false, '18hs' => false], $this->libres($this->ana));
        $this->assertSame(['09hs' => true, '18hs' => true], $this->libres($this->laura));
    }

    public function test_un_bloqueo_de_dia_completo_de_la_profesional_no_aparece_libre(): void
    {
        BloqueoAgenda::create(['user_id' => $this->user->id, 'profesional_id' => $this->ana->id, 'fecha' => self::FECHA]);

        $this->assertSame(['09hs' => false, '18hs' => false], $this->libres($this->ana));
        $this->assertSame(['09hs' => true, '18hs' => true], $this->libres($this->laura));
    }

    public function test_un_bloqueo_de_todo_el_salon_afecta_a_todas(): void
    {
        BloqueoAgenda::create(['user_id' => $this->user->id, 'profesional_id' => null, 'fecha' => self::FECHA]);

        $this->assertSame(['09hs' => false, '18hs' => false], $this->libres($this->laura));
    }

    public function test_un_bloqueo_parcial_solo_tapa_los_horarios_que_toca(): void
    {
        BloqueoAgenda::create([
            'user_id' => $this->user->id, 'profesional_id' => $this->ana->id, 'fecha' => self::FECHA,
            'hora_desde' => '08:00:00', 'hora_hasta' => '10:00:00',
        ]);

        $this->assertSame(['09hs' => false, '18hs' => true], $this->libres($this->ana));
    }
}

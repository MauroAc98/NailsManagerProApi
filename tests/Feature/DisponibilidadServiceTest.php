<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Turno;
use App\Models\User;
use App\Services\Reservas\DisponibilidadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class DisponibilidadServiceTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    private const FECHA = '2099-06-10';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->crearSalon();
    }

    /** "ahora" fijo bien antes de FECHA para que el lead time no interfiera. */
    private function ahora(string $dt = '2099-06-01 09:00:00'): Carbon
    {
        return Carbon::parse($dt);
    }

    private function calcular(array $servicios, ?Profesional $prof = null, ?Carbon $ahora = null, int $lead = 120): array
    {
        return (new DisponibilidadService())->calcular(
            $this->user,
            self::FECHA,
            collect($servicios),
            $prof,
            $ahora ?? $this->ahora(),
            $lead,
        );
    }

    private function horas(array $slots): array
    {
        return array_column($slots, 'hora');
    }

    private function turno(Profesional $prof, string $hora, int $duracion, string $estado = 'confirmado'): Turno
    {
        $cliente = Cliente::firstOrCreate(['user_id' => $this->user->id, 'nombre' => 'C'], ['telefono' => '3765252395']);

        return Turno::create([
            'user_id' => $this->user->id,
            'profesional_id' => $prof->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => self::FECHA . " {$hora}:00",
            'duracion_total_minutos' => $duracion,
            'estado' => $estado,
            'origen' => 'app',
        ]);
    }

    // -- 1.7 ------------------------------------------------------

    public function test_devuelve_los_slots_activos_de_cada_profesional(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $bea->servicios()->attach($s->id);
        $this->crearSlot($this->user, $ana, '10:00');
        $this->crearSlot($this->user, $bea, '11:00');

        $porAna = $this->calcular([$s], $ana);

        $this->assertSame([['hora' => '10:00', 'profesional_ids' => [$ana->id]]], $porAna);
    }

    public function test_ignora_slots_inactivos_y_profesionales_inactivas(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $off = $this->crearProfesional($this->user, 'Off', false);
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $off->servicios()->attach($s->id);
        $this->crearSlot($this->user, $ana, '10:00', false);
        $this->crearSlot($this->user, $ana, '10:30');
        $this->crearSlot($this->user, $off, '12:00');

        $this->assertSame(['10:30'], $this->horas($this->calcular([$s])));
    }

    public function test_slot_legacy_sin_profesional_va_a_la_profesional_por_defecto(): void
    {
        $primera = $this->crearProfesional($this->user, 'Primera');
        $segunda = $this->crearProfesional($this->user, 'Segunda');
        $s = $this->crearServicio($this->user, 'S', 30, true, $primera);
        $segunda->servicios()->attach($s->id);
        $this->crearSlot($this->user, null, '09:00');

        $this->assertSame(
            [['hora' => '09:00', 'profesional_ids' => [$primera->id]]],
            $this->calcular([$s]),
        );
    }

    // -- 1.8 ------------------------------------------------------

    public function test_excluye_solapamiento_parcial_y_admite_adyacente(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 90, true, $ana);
        foreach (['08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30'] as $h) {
            $this->crearSlot($this->user, $ana, $h);
        }
        $this->turno($ana, '10:00', 60); // ocupa [10:00, 11:00)

        // candidato de 90 min: 08:30 -> [08:30,10:00) adyacente OK; 09:00/09:30 pisan el turno;
        // 10:00/10:30 pisan; 11:00 adyacente OK
        $this->assertSame(['08:30', '11:00', '11:30'], $this->horas($this->calcular([$s], $ana)));
    }

    public function test_la_duracion_suma_de_varios_servicios_debe_entrar(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $a = $this->crearServicio($this->user, 'A', 45, true, $ana);
        $b = $this->crearServicio($this->user, 'B', 45, true, $ana);
        $this->crearSlot($this->user, $ana, '09:00');
        $this->crearSlot($this->user, $ana, '10:30');
        $this->turno($ana, '11:00', 30); // [11:00, 11:30)

        // 90 min: 09:00 -> [09:00,10:30) libre; 10:30 -> [10:30,12:00) pisa el turno de 11:00
        $this->assertSame(['09:00'], $this->horas($this->calcular([$a, $b], $ana)));
    }

    public function test_turnos_cancelados_no_bloquean(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '10:00');
        $this->turno($ana, '10:00', 60, 'cancelado');

        $this->assertSame(['10:00'], $this->horas($this->calcular([$s], $ana)));
    }

    public function test_un_turno_de_otra_profesional_no_bloquea(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '10:00');
        $this->turno($bea, '10:00', 60);

        $this->assertSame(['10:00'], $this->horas($this->calcular([$s], $ana)));
    }

    // -- 1.9 ------------------------------------------------------

    public function test_sin_profesional_une_por_hora_y_lista_quien_puede(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $bea->servicios()->attach($s->id);
        $this->crearSlot($this->user, $ana, '10:00');
        $this->crearSlot($this->user, $bea, '10:00');
        $this->crearSlot($this->user, $bea, '10:30');
        $this->turno($ana, '10:00', 30); // Ana ocupada a las 10:00

        $this->assertSame([
            ['hora' => '10:00', 'profesional_ids' => [$bea->id]],
            ['hora' => '10:30', 'profesional_ids' => [$bea->id]],
        ], $this->calcular([$s]));
    }

    public function test_sin_profesional_lista_a_todas_las_que_pueden_en_la_misma_hora(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $bea->servicios()->attach($s->id);
        $this->crearSlot($this->user, $ana, '10:00');
        $this->crearSlot($this->user, $bea, '10:00');

        $this->assertSame(
            [['hora' => '10:00', 'profesional_ids' => [$ana->id, $bea->id]]],
            $this->calcular([$s]),
        );
    }

    public function test_sin_profesional_solo_cuentan_las_que_ofrecen_todos_los_servicios(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $x = $this->crearServicio($this->user, 'X', 30, true, $ana);
        $y = $this->crearServicio($this->user, 'Y', 30, true, $ana);
        $bea->servicios()->attach($x->id); // Bea no ofrece Y
        $this->crearSlot($this->user, $ana, '10:00');
        $this->crearSlot($this->user, $bea, '10:00');

        $this->assertSame(
            [['hora' => '10:00', 'profesional_ids' => [$ana->id]]],
            $this->calcular([$x, $y]),
        );
    }

    public function test_profesional_sin_servicios_asignados_no_ofrece_nada(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30); // sin pivot
        $this->crearSlot($this->user, $ana, '10:00');

        $this->assertSame([], $this->calcular([$s]));
    }

    // -- 1.10 -----------------------------------------------------

    private function reserva(?Profesional $prof, string $slotHora, int $duracion, ?int $expiraEn, string $estado = 'held'): ReservaWeb
    {
        return ReservaWeb::create([
            'user_id' => $this->user->id,
            'profesional_id' => $prof?->id,
            'public_token' => ReservaWeb::generarToken(),
            'servicio_ids' => [1],
            'fecha' => self::FECHA,
            'slot_hora' => $slotHora,
            'duracion_total_minutos' => $duracion,
            'estado' => $estado,
            'expira_en' => $expiraEn,
        ]);
    }

    public function test_un_hold_de_una_profesional_no_bloquea_a_otra(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $bea->servicios()->attach($s->id);
        $this->crearSlot($this->user, $ana, '14:00');
        $this->crearSlot($this->user, $bea, '14:00');
        $ahora = $this->ahora();
        $this->reserva($ana, '14:00:00', 30, $ahora->timestamp + 300);

        $this->assertSame([], $this->calcular([$s], $ana, $ahora));
        $this->assertSame([['hora' => '14:00', 'profesional_ids' => [$bea->id]]], $this->calcular([$s], null, $ahora));
        $this->assertSame([['hora' => '14:00', 'profesional_ids' => [$bea->id]]], $this->calcular([$s], $bea, $ahora));
    }

    public function test_un_hold_vencido_por_expira_en_se_ignora_aunque_siga_held(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '14:00');
        $ahora = $this->ahora();
        $this->reserva($ana, '14:00:00', 30, $ahora->timestamp - 1);
        $this->reserva($ana, '15:00:00', 30, $ahora->timestamp, 'pending_payment'); // expira_en == ahora: ya no vive

        $this->assertSame(['14:00'], $this->horas($this->calcular([$s], null, $ahora)));
    }

    public function test_holds_no_bloqueantes_no_cuentan(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '14:00');
        $ahora = $this->ahora();
        foreach (['expired', 'cancelled', 'confirmed', 'rejected'] as $estado) {
            $this->reserva($ana, '14:00:00', 30, $ahora->timestamp + 500, $estado);
        }

        $this->assertSame(['14:00'], $this->horas($this->calcular([$s], null, $ahora)));
    }

    public function test_un_hold_pending_payment_vivo_bloquea_el_intervalo_completo(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        foreach (['13:30', '14:00', '14:30', '15:00'] as $h) {
            $this->crearSlot($this->user, $ana, $h);
        }
        $ahora = $this->ahora();
        $this->reserva($ana, '14:00:00', 60, $ahora->timestamp + 900, 'pending_payment');

        // hold [14:00,15:00): 13:30 termina en 14:00 (adyacente, libre); 15:00 arranca al fin (libre)
        $this->assertSame(['13:30', '15:00'], $this->horas($this->calcular([$s], $ana, $ahora)));
    }

    // -- estaLibre / ocupacionDelDia ------------------------------

    private function estaLibre(Profesional $prof, string $hora, int $duracion, ?Carbon $ahora = null, ?int $ignorar = null, ?int $lead = null): bool
    {
        return (new DisponibilidadService())->estaLibre($prof->id, self::FECHA, $hora, $duracion, $ahora ?? $this->ahora(), $ignorar, $lead);
    }

    public function test_esta_libre_semiabierto_con_turnos_y_holds(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $ahora = $this->ahora();
        $this->turno($ana, '10:00', 60);
        $this->reserva($ana, '12:00:00', 60, $ahora->timestamp + 600);

        $this->assertFalse($this->estaLibre($ana, '10:30', 30));
        $this->assertTrue($this->estaLibre($ana, '11:00', 60));   // adyacente al turno y al hold
        $this->assertFalse($this->estaLibre($ana, '11:30', 60));  // pisa el hold
        $this->assertFalse($this->estaLibre($ana, '09:30', 60));  // pisa el inicio del turno
        $this->assertTrue($this->estaLibre($ana, '09:00', 60));
    }

    public function test_esta_libre_ignora_la_reserva_indicada_y_los_holds_ajenos_a_la_profesional(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $ahora = $this->ahora();
        $propio = $this->reserva($ana, '12:00:00', 60, $ahora->timestamp + 600);
        $this->reserva($bea, '15:00:00', 60, $ahora->timestamp + 600);

        $this->assertFalse($this->estaLibre($ana, '12:00', 60));
        $this->assertTrue($this->estaLibre($ana, '12:00', 60, null, $propio->id));
        $this->assertTrue($this->estaLibre($ana, '15:00', 60));
    }

    public function test_esta_libre_respeta_el_lead_time_solo_si_se_pide(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $ahora = Carbon::parse(self::FECHA . ' 09:00:00');

        $this->assertTrue($this->estaLibre($ana, '10:00', 30, $ahora));
        $this->assertFalse($this->estaLibre($ana, '10:00', 30, $ahora, null, 120));
        $this->assertTrue($this->estaLibre($ana, '11:00', 30, $ahora, null, 120));
    }

    public function test_ocupacion_del_dia_devuelve_turnos_y_holds_vivos_de_la_profesional(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $ahora = $this->ahora();
        $this->turno($ana, '10:00', 60);
        $this->reserva($ana, '12:00:00', 30, $ahora->timestamp + 600);
        $this->reserva($ana, '13:00:00', 30, $ahora->timestamp - 5); // vencido

        $ocupados = (new DisponibilidadService())->ocupacionDelDia($ana->id, self::FECHA, $ahora);

        $this->assertCount(2, $ocupados);
        $this->assertSame(['10:00', '11:00'], [$ocupados[0][0]->format('H:i'), $ocupados[0][1]->format('H:i')]);
        $this->assertSame(['12:00', '12:30'], [$ocupados[1][0]->format('H:i'), $ocupados[1][1]->format('H:i')]);
    }

    // -- 1.11 -----------------------------------------------------

    public function test_hoy_solo_hay_slots_desde_ahora_mas_anticipacion(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        foreach (['10:00', '12:00'] as $h) {
            $this->crearSlot($this->user, $ana, $h);
        }

        $ahora = Carbon::parse(self::FECHA . ' 09:00:00');

        $this->assertSame(['12:00'], $this->horas($this->calcular([$s], $ana, $ahora, 120)));
    }

    // -- horarios discretos configurados ------------------------------

    public function test_dia_sin_turnos_ofrece_todos_los_slots_activos_y_nada_mas(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        foreach (['09:00', '09:30', '11:30', '14:00', '16:00', '17:30'] as $h) {
            $this->crearSlot($this->user, $ana, $h);
        }
        $this->crearSlot($this->user, $ana, '10:00', false);

        $this->assertSame(
            ['09:00', '09:30', '11:30', '14:00', '16:00', '17:30'],
            $this->horas($this->calcular([$s], $ana)),
        );
    }

    public function test_slots_duplicados_se_ofrecen_una_sola_vez(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '10:00');
        $this->crearSlot($this->user, $ana, '10:00');

        $this->assertSame([['hora' => '10:00', 'profesional_ids' => [$ana->id]]], $this->calcular([$s], $ana));
    }

    public function test_turno_de_90_min_a_las_09_saca_las_0930_pero_ofrece_las_1130(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        foreach (['09:00', '09:30', '11:30', '14:00', '16:00', '17:30'] as $h) {
            $this->crearSlot($this->user, $ana, $h);
        }
        $this->turno($ana, '09:00', 90); // [09:00, 10:30)

        $this->assertSame(['11:30', '14:00', '16:00', '17:30'], $this->horas($this->calcular([$s], $ana)));
    }

    public function test_servicio_largo_se_bloquea_si_pisa_un_turno_posterior_aunque_su_inicio_este_libre(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'Largo', 150, true, $ana);
        foreach (['09:00', '11:30', '14:00'] as $h) {
            $this->crearSlot($this->user, $ana, $h);
        }
        $this->turno($ana, '10:30', 30); // [10:30, 11:00)

        // 09:00 -> [09:00, 11:30) pisa el turno aunque 09:00 este libre.
        $this->assertSame(['11:30', '14:00'], $this->horas($this->calcular([$s], $ana)));
    }

    public function test_inicio_adyacente_al_siguiente_turno_se_ofrece(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 90, true, $ana);
        $this->crearSlot($this->user, $ana, '09:00');
        $this->turno($ana, '10:30', 60);

        $this->assertSame(['09:00'], $this->horas($this->calcular([$s], $ana)));
    }

    public function test_cualquiera_con_una_ocupada_y_otra_libre_devuelve_solo_la_libre(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $s = $this->crearServicio($this->user, 'S', 60, true, $ana);
        $bea->servicios()->attach($s->id);
        foreach ([$ana, $bea] as $p) {
            $this->crearSlot($this->user, $p, '10:00');
            $this->crearSlot($this->user, $p, '12:00');
        }
        $this->turno($ana, '10:00', 60);
        $this->turno($ana, '12:00', 60);
        $this->turno($bea, '12:00', 60);

        // 10:00: solo Bea libre. 12:00: todas ocupadas, desaparece.
        $this->assertSame([['hora' => '10:00', 'profesional_ids' => [$bea->id]]], $this->calcular([$s]));
    }

    public function test_cualquiera_une_horarios_distintos_sin_inventar_intermedios(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $bea->servicios()->attach($s->id);
        $this->crearSlot($this->user, $ana, '09:00');
        $this->crearSlot($this->user, $ana, '10:00');
        $this->crearSlot($this->user, $bea, '09:30');
        $this->crearSlot($this->user, $bea, '10:00');
        $this->crearSlot($this->user, $bea, '11:00');

        $this->assertSame([
            ['hora' => '09:00', 'profesional_ids' => [$ana->id]],
            ['hora' => '09:30', 'profesional_ids' => [$bea->id]],
            ['hora' => '10:00', 'profesional_ids' => [$ana->id, $bea->id]],
            ['hora' => '11:00', 'profesional_ids' => [$bea->id]],
        ], $this->calcular([$s]));
    }

    // -- dias de atencion -------------------------------------------

    public function test_una_profesional_no_trabaja_el_dia_de_la_semana_configurado(): void
    {
        // self::FECHA = 2099-06-10, un miércoles (dayOfWeek 3).
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $bea->servicios()->attach($s->id);
        $ana->update(['dias_atencion' => [1, 2]]); // lunes y martes, no miércoles
        $this->crearSlot($this->user, $ana, '10:00');
        $this->crearSlot($this->user, $bea, '10:00');

        $this->assertSame(
            [['hora' => '10:00', 'profesional_ids' => [$bea->id]]],
            $this->calcular([$s]),
        );
    }

    public function test_una_profesional_sin_dias_atencion_configurados_trabaja_cualquier_dia(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '10:00');

        $this->assertSame(['10:00'], $this->horas($this->calcular([$s], $ana)));
    }

    public function test_el_hold_pisa_solo_los_slots_que_caen_en_su_rango(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        foreach (['14:00', '14:30', '15:00', '15:30'] as $h) {
            $this->crearSlot($this->user, $ana, $h);
        }
        $ahora = $this->ahora();
        $this->reserva($ana, '14:30:00', 60, $ahora->timestamp + 300); // [14:30, 15:30)

        $this->assertSame(['14:00', '15:30'], $this->horas($this->calcular([$s], $ana, $ahora)));
    }
}

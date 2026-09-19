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

    private function calcular(array $servicios, ?Profesional $prof = null, ?Carbon $ahora = null, int $lead = 120, int $ventana = 15): array
    {
        return (new DisponibilidadService())->calcular(
            $this->user,
            self::FECHA,
            collect($servicios),
            $prof,
            $ahora ?? $this->ahora(),
            $lead,
            $ventana,
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

        // rango 09:00-10:30, paso 30. 90 min: 09:00 y 09:30 (termina 11:00, adyacente) libres;
        // 10:00 y 10:30 pisan el turno de 11:00
        $this->assertSame(['09:00', '09:30'], $this->horas($this->calcular([$a, $b], $ana)));
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

    private function reserva(string $slotHora, int $duracion, Carbon $creada, string $estado = 'pending_payment'): ReservaWeb
    {
        $r = new ReservaWeb([
            'user_id' => $this->user->id,
            'nombre_completo' => 'Web',
            'telefono' => '+5491155551234',
            'servicio_ids' => [1],
            'fecha' => self::FECHA,
            'slot_hora' => $slotHora,
            'duracion_total_minutos' => $duracion,
            'estado' => $estado,
        ]);
        $r->created_at = $creada;
        $r->updated_at = $creada;
        $r->save();

        return $r;
    }

    public function test_una_reserva_pendiente_reciente_bloquea_a_todas_las_profesionales(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $bea->servicios()->attach($s->id);
        $this->crearSlot($this->user, $ana, '14:00');
        $this->crearSlot($this->user, $bea, '14:00');
        $this->crearSlot($this->user, $bea, '15:00');
        $ahora = $this->ahora();
        $this->reserva('14:00:00', 30, $ahora->copy()->subMinutes(5));

        // Bea: rango 14:00-15:00; el hold [14:00,14:30) saca 14:00 y deja 14:30 (adyacente)
        $this->assertSame(['14:30', '15:00'], $this->horas($this->calcular([$s], null, $ahora)));
    }

    public function test_una_reserva_pendiente_vieja_se_ignora(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '14:00');
        $ahora = $this->ahora();
        $this->reserva('14:00:00', 30, $ahora->copy()->subMinutes(20));

        $this->assertSame(['14:00'], $this->horas($this->calcular([$s], null, $ahora)));
    }

    public function test_reservas_rechazadas_no_bloquean_como_hold(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '14:00');
        $ahora = $this->ahora();
        $this->reserva('14:00:00', 30, $ahora->copy()->subMinutes(1), 'rejected');

        $this->assertSame(['14:00'], $this->horas($this->calcular([$s], null, $ahora)));
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

        $this->assertSame(['11:00', '11:30', '12:00'], $this->horas($this->calcular([$s], $ana, $ahora, 120)));
    }

    // -- rango [min,max] con grilla ----------------------------------

    public function test_ofrece_horarios_entre_medio_dentro_del_rango(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '09:00');
        $this->crearSlot($this->user, $ana, '12:00');

        $this->assertSame(
            ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00'],
            $this->horas($this->calcular([$s], $ana)),
        );
    }

    public function test_el_paso_es_configurable(): void
    {
        config(['reservas.paso_minutos' => 15]);
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '09:00');
        $this->crearSlot($this->user, $ana, '10:00');

        $this->assertSame(['09:00', '09:15', '09:30', '09:45', '10:00'], $this->horas($this->calcular([$s], $ana)));
    }

    public function test_la_grilla_se_alinea_al_minimo_del_rango(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '09:10');
        $this->crearSlot($this->user, $ana, '10:45');

        // 09:10, 09:40, 10:10, 10:40 (el proximo, 11:10, excede el maximo)
        $this->assertSame(['09:10', '09:40', '10:10', '10:40'], $this->horas($this->calcular([$s], $ana)));
    }

    public function test_un_turno_saca_los_candidatos_que_lo_pisan_con_limites_exactos(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 60, true, $ana);
        $this->crearSlot($this->user, $ana, '09:00');
        $this->crearSlot($this->user, $ana, '12:00');
        $this->turno($ana, '10:00', 90); // [10:00, 11:30)

        // 60 min: 09:00 -> [9,10) adyacente OK; 09:30 -> [9:30,10:30) pisa; 10:00-11:00 pisan;
        // 11:30 -> [11:30,12:30) adyacente OK; 12:00 OK
        $this->assertSame(['09:00', '11:30', '12:00'], $this->horas($this->calcular([$s], $ana)));
    }

    public function test_servicio_de_90_min_pierde_las_0930_si_hay_turno_a_las_10(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 90, true, $ana);
        $this->crearSlot($this->user, $ana, '09:00');
        $this->crearSlot($this->user, $ana, '09:30');
        $this->turno($ana, '10:00', 60);

        // 09:00 -> [9:00,10:30) pisa; 09:30 pisa
        $this->assertSame([], $this->calcular([$s], $ana));
    }

    public function test_profesional_con_un_solo_slot_ofrece_solo_esa_hora(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '15:00');

        $this->assertSame(['15:00'], $this->horas($this->calcular([$s], $ana)));
    }

    public function test_el_inicio_debe_estar_en_el_rango_pero_el_fin_puede_pasarse_del_maximo(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 120, true, $ana);
        $this->crearSlot($this->user, $ana, '17:00');
        $this->crearSlot($this->user, $ana, '18:00');

        // 18:00 termina 20:00, fuera del rango, pero solo se exige el inicio (regla de la agenda interna)
        $this->assertSame(['17:00', '17:30', '18:00'], $this->horas($this->calcular([$s], $ana)));
    }

    public function test_dos_profesionales_con_rangos_distintos_bajo_cualquiera(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $bea->servicios()->attach($s->id);
        $this->crearSlot($this->user, $ana, '09:00');
        $this->crearSlot($this->user, $ana, '10:00');
        $this->crearSlot($this->user, $bea, '09:30');
        $this->crearSlot($this->user, $bea, '11:00');

        $this->assertSame([
            ['hora' => '09:00', 'profesional_ids' => [$ana->id]],
            ['hora' => '09:30', 'profesional_ids' => [$ana->id, $bea->id]],
            ['hora' => '10:00', 'profesional_ids' => [$ana->id, $bea->id]],
            ['hora' => '10:30', 'profesional_ids' => [$bea->id]],
            ['hora' => '11:00', 'profesional_ids' => [$bea->id]],
        ], $this->calcular([$s]));
    }

    public function test_nunca_ofrece_fuera_del_rango(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '10:00');
        $this->crearSlot($this->user, $ana, '11:00');

        $horas = $this->horas($this->calcular([$s], $ana));
        $this->assertSame('10:00', min($horas));
        $this->assertSame('11:00', max($horas));
    }

    public function test_el_hold_pisa_horarios_intermedios(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '14:00');
        $this->crearSlot($this->user, $ana, '15:00');
        $ahora = $this->ahora();
        $this->reserva('14:30:00', 60, $ahora->copy()->subMinutes(2)); // [14:30, 15:30)

        $this->assertSame(['14:00'], $this->horas($this->calcular([$s], $ana, $ahora)));
    }
}

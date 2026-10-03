<?php

namespace Tests\Feature;

use App\Models\ReservaWeb;
use App\Services\Reservas\AgendaChecks;
use Illuminate\Support\Carbon;
use Tests\Feature\Contract\AdminContractTestCase;

/**
 * combo-multi-profesional, PR 6b: chequeos de agenda del flujo de la duena
 * (rango de atencion, choque con turnos, hold vivo) extraidos de TurnoController
 * para reusarlos por tramo. No incluyen la regla de slots (solo flujos de clienta).
 */
class AgendaChecksTest extends AdminContractTestCase
{
    private function checks(): AgendaChecks
    {
        return app(AgendaChecks::class);
    }

    public function test_el_horario_de_atencion_se_valida_contra_el_primer_y_ultimo_slot(): void
    {
        $this->assertNull($this->checks()->horarioAtencion($this->ana->id, Carbon::parse('2099-06-10 12:10')));
        $this->assertStringContainsString('09:00 a 18:00', $this->checks()->horarioAtencion($this->ana->id, Carbon::parse('2099-06-10 21:00')));
    }

    public function test_sin_slots_configurados_avisa_que_no_hay_horarios(): void
    {
        $this->ana->slotsDisponibles()->delete();

        $this->assertStringContainsString('No tenés horarios de atención', $this->checks()->horarioAtencion($this->ana->id, Carbon::parse('2099-06-10 10:00')));
    }

    public function test_choque_devuelve_el_turno_que_se_pisa_y_null_si_solo_se_tocan(): void
    {
        $turno = $this->crearTurno('confirmado', '2099-06-10 10:00:00'); // 10:00-10:30

        $this->assertSame($turno->id, $this->checks()->choque($this->ana->id, '2099-06-10 10:15:00', 30)?->id);
        $this->assertNull($this->checks()->choque($this->ana->id, '2099-06-10 10:30:00', 30));
        $this->assertNull($this->checks()->choque($this->ana->id, '2099-06-10 10:15:00', 30, $turno->id));
    }

    public function test_hold_vivo_detecta_una_reserva_online_en_curso_que_pisa_el_horario(): void
    {
        Carbon::setTestNow('2099-06-01 09:00:00');
        ReservaWeb::create([
            'user_id' => $this->user->id, 'profesional_id' => $this->ana->id, 'public_token' => ReservaWeb::generarToken(),
            'servicio_ids' => [$this->servicio->id], 'estado' => 'held', 'fecha' => '2099-06-10', 'slot_hora' => '10:00:00',
            'duracion_total_minutos' => 30, 'expira_en' => Carbon::now()->timestamp + 600,
        ]);

        $this->assertTrue($this->checks()->holdVivo($this->ana->id, '2099-06-10 10:15:00', 30));
        $this->assertFalse($this->checks()->holdVivo($this->ana->id, '2099-06-10 11:00:00', 30));
        Carbon::setTestNow();
    }
}

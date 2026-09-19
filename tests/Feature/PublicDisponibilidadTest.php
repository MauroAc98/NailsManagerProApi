<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class PublicDisponibilidadTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // 2099-06-10 09:00 en la zona de la app.
        Carbon::setTestNow(Carbon::parse('2099-06-10 09:00:00'));
        $this->user = $this->crearSalon();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(string $query): string
    {
        return "/api/public/{$this->user->slug}/disponibilidad?{$query}";
    }

    public function test_contrato_json_exacto_con_varias_profesionales(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $x = $this->crearServicio($this->user, 'X', 45, true, $ana);
        $y = $this->crearServicio($this->user, 'Y', 45, true, $ana);
        $bea->servicios()->attach([$x->id, $y->id]);
        $this->crearSlot($this->user, $ana, '12:00');
        $this->crearSlot($this->user, $ana, '12:30');
        $this->crearSlot($this->user, $bea, '12:30');

        $this->getJson($this->url("fecha=2099-06-11&servicio_ids[]={$x->id}&servicio_ids[]={$y->id}"))
            ->assertOk()
            ->assertExactJson([
                'fecha' => '2099-06-11',
                'duracion_total_minutos' => 90,
                'slots' => [
                    ['hora' => '12:00', 'profesional_ids' => [$ana->id]],
                    ['hora' => '12:30', 'profesional_ids' => [$ana->id, $bea->id]],
                ],
            ]);
    }

    public function test_con_profesional_id_filtra_a_esa_profesional(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $bea->servicios()->attach($s->id);
        $this->crearSlot($this->user, $ana, '12:00');
        $this->crearSlot($this->user, $bea, '13:00');

        $this->getJson($this->url("fecha=2099-06-11&servicio_ids[]={$s->id}&profesional_id={$bea->id}"))
            ->assertOk()
            ->assertJsonPath('slots', [['hora' => '13:00', 'profesional_ids' => [$bea->id]]]);
    }

    public function test_hoy_respeta_la_anticipacion_minima(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '10:00');
        $this->crearSlot($this->user, $ana, '11:00');

        // ahora 09:00 + 120 min = 11:00 (inclusive)
        $this->getJson($this->url("fecha=2099-06-10&servicio_ids[]={$s->id}"))
            ->assertOk()
            ->assertJsonPath('slots', [['hora' => '11:00', 'profesional_ids' => [$ana->id]]]);
    }

    public function test_ofrece_solo_los_slots_configurados_sin_horarios_intermedios(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '12:00');
        $this->crearSlot($this->user, $ana, '13:00');

        $this->getJson($this->url("fecha=2099-06-11&servicio_ids[]={$s->id}"))
            ->assertOk()
            ->assertJsonPath('slots', [
                ['hora' => '12:00', 'profesional_ids' => [$ana->id]],
                ['hora' => '13:00', 'profesional_ids' => [$ana->id]],
            ]);
    }

    public function test_fecha_pasada_da_422(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);

        $this->getJson($this->url("fecha=2099-06-09&servicio_ids[]={$s->id}"))->assertStatus(422);
    }

    public function test_fecha_con_formato_invalido_da_422(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);

        $this->getJson($this->url("fecha=manana&servicio_ids[]={$s->id}"))->assertStatus(422);
    }

    public function test_sin_servicio_ids_da_422(): void
    {
        $this->getJson($this->url('fecha=2099-06-11'))->assertStatus(422);
    }

    public function test_servicio_de_otro_salon_o_inactivo_da_422(): void
    {
        $otro = $this->crearSalon();
        $ajeno = $this->crearServicio($otro, 'Ajeno');
        $inactivo = $this->crearServicio($this->user, 'Inactivo', 30, false);

        $this->getJson($this->url("fecha=2099-06-11&servicio_ids[]={$ajeno->id}"))->assertStatus(422);
        $this->getJson($this->url("fecha=2099-06-11&servicio_ids[]={$inactivo->id}"))->assertStatus(422);
    }

    public function test_profesional_que_no_ofrece_el_servicio_da_422(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);

        $this->getJson($this->url("fecha=2099-06-11&servicio_ids[]={$s->id}&profesional_id={$bea->id}"))->assertStatus(422);
    }

    public function test_profesional_de_otro_salon_da_404(): void
    {
        $otro = $this->crearSalon();
        $ajena = $this->crearProfesional($otro, 'Ajena');
        $s = $this->crearServicio($this->user, 'S');

        $this->getJson($this->url("fecha=2099-06-11&servicio_ids[]={$s->id}&profesional_id={$ajena->id}"))->assertNotFound();
    }

    public function test_salon_con_suscripcion_vencida_da_404(): void
    {
        $user = $this->crearSalon(['is_exempt' => false]);
        $this->crearSuscripcion($user, 'VENCIDO', now()->subDay());

        $this->getJson("/api/public/{$user->slug}/disponibilidad?fecha=2099-06-11&servicio_ids[]=1")->assertNotFound();
    }

    public function test_slots_de_otro_salon_no_se_mezclan(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $otro = $this->crearSalon();
        $ajena = $this->crearProfesional($otro, 'Ajena');
        $this->crearSlot($otro, $ajena, '15:00');

        $this->getJson($this->url("fecha=2099-06-11&servicio_ids[]={$s->id}"))
            ->assertOk()
            ->assertJsonPath('slots', []);
    }
}

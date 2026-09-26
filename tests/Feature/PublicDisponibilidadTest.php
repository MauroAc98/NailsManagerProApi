<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\Servicio;
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

    /**
     * Construye la query string de `asignaciones` (combo-multi-profesional,
     * PR 3a3: es la UNICA forma de pedir disponibilidad). Cada grupo es
     * `[servicio_ids, profesional_id|null]`; `profesional_id` null se omite
     * (= "Cualquiera").
     *
     * @param  array<int, array{0: array<int,int>, 1: int|null}>  $grupos
     */
    private function asignaciones(array $grupos): string
    {
        $partes = [];
        foreach ($grupos as $i => [$servicioIds, $profesionalId]) {
            foreach ($servicioIds as $id) {
                $partes[] = "asignaciones[{$i}][servicio_ids][]={$id}";
            }
            if ($profesionalId !== null) {
                $partes[] = "asignaciones[{$i}][profesional_id]={$profesionalId}";
            }
        }

        return implode('&', $partes);
    }

    private function url(string $fecha, array $grupos): string
    {
        $query = $this->asignaciones($grupos);

        return "/api/public/{$this->user->slug}/disponibilidad?fecha={$fecha}&{$query}";
    }

    /** @return array{0: Profesional, 1: Servicio} */
    private function promoConComponentes(Profesional $ana, Profesional $laura, string $modo = 'secuencia'): array
    {
        $softgel = $this->crearServicio($this->user, 'Softgel', 60, true, $ana);
        $semis = $this->crearServicio($this->user, 'Semis pies', 45, true, $laura);
        $promo = Servicio::create([
            'user_id' => $this->user->id, 'nombre' => 'Softgel + Semis pies',
            'duracion_minutos' => 105, 'precio' => 22000, 'activo' => true,
            'es_promo' => true, 'modo_promo' => $modo,
        ]);
        $promo->componentes()->create(['componente_servicio_id' => $softgel->id, 'profesional_id' => $ana->id, 'orden' => 1]);
        $promo->componentes()->create(['componente_servicio_id' => $semis->id, 'profesional_id' => $laura->id, 'orden' => 2]);

        return [$promo];
    }

    // ── Rule L: single-group requests keep today's exact response shape ──

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

        $this->getJson($this->url('2099-06-11', [[[$x->id, $y->id], null]]))
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

        $this->getJson($this->url('2099-06-11', [[[$s->id], $bea->id]]))
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
        $this->getJson($this->url('2099-06-10', [[[$s->id], null]]))
            ->assertOk()
            ->assertJsonPath('slots', [['hora' => '11:00', 'profesional_ids' => [$ana->id]]]);
    }

    public function test_ofrece_solo_los_slots_configurados_sin_horarios_intermedios(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '12:00');
        $this->crearSlot($this->user, $ana, '13:00');

        $this->getJson($this->url('2099-06-11', [[[$s->id], null]]))
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

        $this->getJson($this->url('2099-06-09', [[[$s->id], null]]))->assertStatus(422);
    }

    public function test_fecha_con_formato_invalido_da_422(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);

        $this->getJson($this->url('manana', [[[$s->id], null]]))->assertStatus(422);
    }

    public function test_sin_asignaciones_da_422(): void
    {
        $this->getJson("/api/public/{$this->user->slug}/disponibilidad?fecha=2099-06-11")->assertStatus(422);
    }

    public function test_servicio_de_otro_salon_o_inactivo_da_422(): void
    {
        $otro = $this->crearSalon();
        $ajeno = $this->crearServicio($otro, 'Ajeno');
        $inactivo = $this->crearServicio($this->user, 'Inactivo', 30, false);

        $this->getJson($this->url('2099-06-11', [[[$ajeno->id], null]]))->assertStatus(422);
        $this->getJson($this->url('2099-06-11', [[[$inactivo->id], null]]))->assertStatus(422);
    }

    public function test_profesional_que_no_ofrece_el_servicio_da_422(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);

        $this->getJson($this->url('2099-06-11', [[[$s->id], $bea->id]]))->assertStatus(422);
    }

    public function test_profesional_de_otro_salon_da_404(): void
    {
        $otro = $this->crearSalon();
        $ajena = $this->crearProfesional($otro, 'Ajena');
        $s = $this->crearServicio($this->user, 'S');

        $this->getJson($this->url('2099-06-11', [[[$s->id], $ajena->id]]))->assertNotFound();
    }

    public function test_salon_con_suscripcion_vencida_da_404(): void
    {
        $user = $this->crearSalon(['is_exempt' => false]);
        $this->crearSuscripcion($user, 'VENCIDO', now()->subDay());

        $this->getJson("/api/public/{$user->slug}/disponibilidad?fecha=2099-06-11&asignaciones[0][servicio_ids][]=1")->assertNotFound();
    }

    public function test_slots_de_otro_salon_no_se_mezclan(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $otro = $this->crearSalon();
        $ajena = $this->crearProfesional($otro, 'Ajena');
        $this->crearSlot($otro, $ajena, '15:00');

        $this->getJson($this->url('2099-06-11', [[[$s->id], null]]))
            ->assertOk()
            ->assertJsonPath('slots', []);
    }

    // ── 3a3: promo componentizada y grupos sueltos multi-profesional ──

    public function test_promo_componentizada_ofrece_hora_fin_modo_y_tramos(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $laura = $this->crearProfesional($this->user, 'Laura');
        [$promo] = $this->promoConComponentes($ana, $laura);
        $this->crearSlot($this->user, $ana, '10:00');
        $this->crearSlot($this->user, $laura, '11:00');

        $this->getJson($this->url('2099-06-11', [[[$promo->id], null]]))
            ->assertOk()
            ->assertJsonPath('slots.0.hora', '10:00')
            ->assertJsonPath('slots.0.fin', '11:45')
            ->assertJsonPath('slots.0.modo', 'secuencia')
            ->assertJsonPath('slots.0.profesional_ids', [$ana->id, $laura->id])
            ->assertJsonCount(2, 'slots.0.tramos')
            ->assertJsonMissingPath('duracion_total_minutos');
    }

    public function test_dos_grupos_sueltos_sin_profesional_explicita_da_422(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $laura = $this->crearProfesional($this->user, 'Laura');
        $softgel = $this->crearServicio($this->user, 'Softgel', 60, true, $ana);
        $semis = $this->crearServicio($this->user, 'Semis pies', 45, true, $laura);

        $this->getJson($this->url('2099-06-11', [[[$softgel->id], $ana->id], [[$semis->id], null]]))
            ->assertStatus(422);
    }

    public function test_dos_grupos_sueltos_con_profesionales_cae_a_secuencia_con_fin(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $laura = $this->crearProfesional($this->user, 'Laura');
        $softgel = $this->crearServicio($this->user, 'Softgel', 60, true, $ana);
        $semis = $this->crearServicio($this->user, 'Semis pies', 45, true, $laura);
        $this->crearSlot($this->user, $ana, '10:00');
        $this->crearSlot($this->user, $laura, '11:00');

        $this->getJson($this->url('2099-06-11', [[[$softgel->id], $ana->id], [[$semis->id], $laura->id]]))
            ->assertOk()
            ->assertJsonPath('slots.0.hora', '10:00')
            ->assertJsonPath('slots.0.fin', '11:45')
            ->assertJsonPath('slots.0.modo', 'secuencia');
    }

    public function test_dos_promos_combinadas_da_422(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $laura = $this->crearProfesional($this->user, 'Laura');
        [$promoUno] = $this->promoConComponentes($ana, $laura);
        [$promoDos] = $this->promoConComponentes($ana, $laura);

        $this->getJson($this->url('2099-06-11', [[[$promoUno->id], null], [[$promoDos->id], null]]))
            ->assertStatus(422);
    }
}

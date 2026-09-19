<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ReservaWeb;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class PublicDisponibilidadDiasTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // 2099-06-10 09:00; ventana 30 dias -> hasta 2099-07-10.
        Carbon::setTestNow(Carbon::parse('2099-06-10 09:00:00'));
        config(['reservas.ventana_dias' => 30]);
        $this->user = $this->crearSalon();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(string $query): string
    {
        return "/api/public/{$this->user->slug}/disponibilidad/dias?{$query}";
    }

    private function turno($prof, string $fechaHora, int $duracion): Turno
    {
        $cliente = Cliente::firstOrCreate(['user_id' => $this->user->id, 'nombre' => 'C'], ['telefono' => '3765252395']);

        return Turno::create([
            'user_id' => $this->user->id,
            'profesional_id' => $prof->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => $fechaHora,
            'duracion_total_minutos' => $duracion,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
    }

    private function hold(string $fecha, string $hora, int $duracion): ReservaWeb
    {
        $r = new ReservaWeb([
            'user_id' => $this->user->id,
            'nombre_completo' => 'Web',
            'telefono' => '+5491155551234',
            'servicio_ids' => [1],
            'fecha' => $fecha,
            'slot_hora' => $hora,
            'duracion_total_minutos' => $duracion,
            'estado' => 'pending_payment',
        ]);
        $r->created_at = Carbon::now();
        $r->updated_at = Carbon::now();
        $r->save();

        return $r;
    }

    /** @return array{0: \App\Models\Profesional, 1: \App\Models\Servicio} */
    private function salonBasico(string $desdeHora = '12:00', string $hastaHora = '13:00', int $duracion = 30): array
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', $duracion, true, $ana);
        $this->crearSlot($this->user, $ana, $desdeHora);
        $this->crearSlot($this->user, $ana, $hastaHora);

        return [$ana, $s];
    }

    public function test_contrato_json_exacto_solo_con_dias_que_tienen_lugar(): void
    {
        [, $s] = $this->salonBasico(); // slots 12:00 y 13:00 -> 2 inicios por dia (sin horarios intermedios)

        $this->getJson($this->url("desde=2099-06-11&hasta=2099-06-12&servicio_ids[]={$s->id}"))
            ->assertOk()
            ->assertExactJson(['dias' => [
                ['fecha' => '2099-06-11', 'libres' => 2],
                ['fecha' => '2099-06-12', 'libres' => 2],
            ]]);
    }

    public function test_cuenta_solo_los_slots_activos_que_entran_sin_pisar_turnos(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 60, true, $ana);
        foreach (['09:00', '09:30', '11:30', '14:00'] as $h) {
            $this->crearSlot($this->user, $ana, $h);
        }
        $this->crearSlot($this->user, $ana, '16:00', false);
        $this->turno($ana, '2099-06-11 09:00:00', 90); // saca 09:00 y 09:30

        $this->getJson($this->url("desde=2099-06-11&hasta=2099-06-12&servicio_ids[]={$s->id}"))
            ->assertOk()
            ->assertExactJson(['dias' => [
                ['fecha' => '2099-06-11', 'libres' => 2],
                ['fecha' => '2099-06-12', 'libres' => 4],
            ]]);
    }

    public function test_sin_slots_no_hay_dias(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);

        $this->getJson($this->url("desde=2099-06-11&hasta=2099-06-13&servicio_ids[]={$s->id}"))
            ->assertOk()->assertExactJson(['dias' => []]);
    }

    public function test_dia_totalmente_ocupado_se_excluye(): void
    {
        [$ana, $s] = $this->salonBasico('12:00', '13:00', 30);
        $this->turno($ana, '2099-06-11 11:30:00', 180); // tapa los dos slots (12:00 y 13:00)

        $this->getJson($this->url("desde=2099-06-11&hasta=2099-06-12&servicio_ids[]={$s->id}"))
            ->assertOk()
            ->assertExactJson(['dias' => [['fecha' => '2099-06-12', 'libres' => 2]]]);
    }

    public function test_un_hold_vigente_quita_el_dia(): void
    {
        [, $s] = $this->salonBasico('12:00', '13:00', 30);
        $this->hold('2099-06-11', '11:30:00', 180);

        $this->getJson($this->url("desde=2099-06-11&hasta=2099-06-12&servicio_ids[]={$s->id}"))
            ->assertOk()
            ->assertJsonPath('dias.0.fecha', '2099-06-12')
            ->assertJsonCount(1, 'dias');
    }

    public function test_hoy_sin_anticipacion_suficiente_no_aparece(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $this->crearSlot($this->user, $ana, '10:00'); // ahora 09:00 + 120 = 11:00

        $this->getJson($this->url("desde=2099-06-10&hasta=2099-06-11&servicio_ids[]={$s->id}"))
            ->assertOk()
            ->assertExactJson(['dias' => [['fecha' => '2099-06-11', 'libres' => 1]]]);
    }

    public function test_desde_pasado_se_recorta_a_hoy(): void
    {
        [, $s] = $this->salonBasico();

        $this->getJson($this->url("desde=2099-06-01&hasta=2099-06-11&servicio_ids[]={$s->id}"))
            ->assertOk()
            ->assertJsonPath('dias.0.fecha', '2099-06-10')
            ->assertJsonPath('dias.1.fecha', '2099-06-11')
            ->assertJsonCount(2, 'dias');
    }

    public function test_hasta_mas_alla_de_la_ventana_se_recorta(): void
    {
        [, $s] = $this->salonBasico('12:00', '12:00');

        $r = $this->getJson($this->url("desde=2099-07-08&hasta=2099-07-20&servicio_ids[]={$s->id}"))->assertOk();
        $this->assertSame(['2099-07-08', '2099-07-09', '2099-07-10'], array_column($r->json('dias'), 'fecha'));
    }

    public function test_rango_totalmente_fuera_de_la_ventana_da_vacio(): void
    {
        [, $s] = $this->salonBasico();

        $this->getJson($this->url("desde=2099-08-01&hasta=2099-08-10&servicio_ids[]={$s->id}"))
            ->assertOk()->assertExactJson(['dias' => []]);
    }

    public function test_rango_de_46_dias_da_422_y_45_dias_es_valido(): void
    {
        [, $s] = $this->salonBasico();

        // 2099-06-10 .. 2099-07-24 = 45 dias inclusive
        $this->getJson($this->url("desde=2099-06-10&hasta=2099-07-24&servicio_ids[]={$s->id}"))->assertOk();
        $this->getJson($this->url("desde=2099-06-10&hasta=2099-07-25&servicio_ids[]={$s->id}"))->assertStatus(422);
    }

    public function test_hasta_menor_que_desde_da_422(): void
    {
        [, $s] = $this->salonBasico();

        $this->getJson($this->url("desde=2099-06-12&hasta=2099-06-11&servicio_ids[]={$s->id}"))->assertStatus(422);
    }

    public function test_formato_de_fecha_estricto_y_parametros_requeridos(): void
    {
        [, $s] = $this->salonBasico();

        $this->getJson($this->url("desde=manana&hasta=2099-06-11&servicio_ids[]={$s->id}"))->assertStatus(422);
        $this->getJson($this->url("desde=2099-6-11&hasta=2099-06-12&servicio_ids[]={$s->id}"))->assertStatus(422);
        $this->getJson($this->url("hasta=2099-06-11&servicio_ids[]={$s->id}"))->assertStatus(422);
        $this->getJson($this->url('desde=2099-06-11&hasta=2099-06-12'))->assertStatus(422);
    }

    public function test_servicio_ajeno_o_inactivo_da_422(): void
    {
        $otro = $this->crearSalon();
        $ajeno = $this->crearServicio($otro, 'Ajeno');
        $inactivo = $this->crearServicio($this->user, 'Inactivo', 30, false);

        $this->getJson($this->url("desde=2099-06-11&hasta=2099-06-12&servicio_ids[]={$ajeno->id}"))->assertStatus(422);
        $this->getJson($this->url("desde=2099-06-11&hasta=2099-06-12&servicio_ids[]={$inactivo->id}"))->assertStatus(422);
    }

    public function test_profesional_que_no_ofrece_el_servicio_da_422_y_ajena_da_404(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $ajena = $this->crearProfesional($this->crearSalon(), 'Ajena');

        $this->getJson($this->url("desde=2099-06-11&hasta=2099-06-12&servicio_ids[]={$s->id}&profesional_id={$bea->id}"))->assertStatus(422);
        $this->getJson($this->url("desde=2099-06-11&hasta=2099-06-12&servicio_ids[]={$s->id}&profesional_id={$ajena->id}"))->assertNotFound();
    }

    public function test_con_profesional_id_filtra_a_esa_profesional(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $bea = $this->crearProfesional($this->user, 'Bea');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $bea->servicios()->attach($s->id);
        $this->crearSlot($this->user, $ana, '12:00');
        $this->crearSlot($this->user, $bea, '13:00');
        $this->turno($bea, '2099-06-11 12:30:00', 120); // Bea ocupada 12:30-14:30

        $this->getJson($this->url("desde=2099-06-11&hasta=2099-06-11&servicio_ids[]={$s->id}&profesional_id={$bea->id}"))
            ->assertOk()->assertExactJson(['dias' => []]);
        $this->getJson($this->url("desde=2099-06-11&hasta=2099-06-11&servicio_ids[]={$s->id}&profesional_id={$ana->id}"))
            ->assertOk()->assertExactJson(['dias' => [['fecha' => '2099-06-11', 'libres' => 1]]]);
    }

    public function test_salon_ajeno_y_suscripcion_vencida_dan_404(): void
    {
        $vencido = $this->crearSalon(['is_exempt' => false]);
        $this->crearSuscripcion($vencido, 'VENCIDO', now()->subDay());

        $this->getJson("/api/public/{$vencido->slug}/disponibilidad/dias?desde=2099-06-11&hasta=2099-06-12&servicio_ids[]=1")->assertNotFound();
        $this->getJson('/api/public/no-existe/disponibilidad/dias?desde=2099-06-11&hasta=2099-06-12&servicio_ids[]=1')->assertNotFound();
    }

    public function test_los_slots_de_otro_salon_no_se_mezclan(): void
    {
        $ana = $this->crearProfesional($this->user, 'Ana');
        $s = $this->crearServicio($this->user, 'S', 30, true, $ana);
        $otro = $this->crearSalon();
        $this->crearSlot($otro, $this->crearProfesional($otro, 'Ajena'), '15:00');

        $this->getJson($this->url("desde=2099-06-11&hasta=2099-06-12&servicio_ids[]={$s->id}"))
            ->assertOk()->assertExactJson(['dias' => []]);
    }

    public function test_el_rango_equivale_a_la_union_de_los_dias_individuales(): void
    {
        [$ana, $s] = $this->salonBasico('12:00', '15:00', 60);
        $this->turno($ana, '2099-06-12 12:00:00', 90);
        $this->turno($ana, '2099-06-14 11:00:00', 300);
        $this->hold('2099-06-15', '13:00:00', 60);

        $esperado = [];
        for ($d = Carbon::parse('2099-06-10'); $d->lte(Carbon::parse('2099-06-20')); $d->addDay()) {
            $slots = $this->getJson("/api/public/{$this->user->slug}/disponibilidad?fecha={$d->format('Y-m-d')}&servicio_ids[]={$s->id}")->json('slots');
            if (count($slots) > 0) {
                $esperado[] = ['fecha' => $d->format('Y-m-d'), 'libres' => count($slots)];
            }
        }

        $this->assertNotEmpty($esperado);
        $this->assertLessThan(11, count($esperado)); // algun dia quedo excluido
        $this->getJson($this->url("desde=2099-06-10&hasta=2099-06-20&servicio_ids[]={$s->id}"))
            ->assertOk()->assertExactJson(['dias' => $esperado]);
    }

    public function test_la_cantidad_de_consultas_no_depende_del_largo_del_rango(): void
    {
        [, $s] = $this->salonBasico();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($this->url("desde=2099-06-11&hasta=2099-06-11&servicio_ids[]={$s->id}"))->assertOk();
        $uno = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->getJson($this->url("desde=2099-06-11&hasta=2099-07-10&servicio_ids[]={$s->id}"))->assertOk();
        $treinta = count(DB::getQueryLog());

        $this->assertSame($uno, $treinta);
    }
}

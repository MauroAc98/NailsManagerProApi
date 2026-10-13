<?php

namespace Tests\Feature;

use App\Models\BloqueoAgenda;
use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

// GET /api/public/{slug}/servicios?con_turnos=1 marca con `sin_turnos` los
// servicios que no tienen NINGUN inicio libre en la ventana reservable. Usa la
// misma logica que /disponibilidad: lo que se lista como disponible se puede
// reservar (salvo que alguien lo tome en el medio).
class PublicServiciosSinTurnosTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    private User $user;
    private Profesional $ana;

    protected function setUp(): void
    {
        parent::setUp();
        // 2099-06-10 09:00; ventana 30 dias -> hasta 2099-07-10.
        Carbon::setTestNow(Carbon::parse('2099-06-10 09:00:00'));
        config(['reservas.ventana_dias' => 30, 'reservas.anticipacion_minutos' => 120]);
        Cache::flush();
        $this->user = $this->crearSalon();
        $this->ana = $this->crearProfesional($this->user, 'Ana');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function listado(string $query = '?con_turnos=1'): array
    {
        return collect($this->getJson("/api/public/{$this->user->slug}/servicios{$query}")->assertOk()->json())->keyBy('id')->all();
    }

    private function turno(Profesional $prof, string $fechaHora, int $duracion): Turno
    {
        $cliente = Cliente::firstOrCreate(['user_id' => $this->user->id, 'nombre' => 'C'], ['telefono' => '3765252395']);

        return Turno::create([
            'user_id' => $this->user->id, 'profesional_id' => $prof->id, 'cliente_id' => $cliente->id,
            'fecha_hora' => $fechaHora, 'duracion_total_minutos' => $duracion, 'estado' => 'confirmado', 'origen' => 'app',
        ]);
    }

    /** Ocupa el slot de las 12:00 de TODOS los dias de la ventana. */
    private function llenarAgenda(Profesional $prof, int $duracion = 60): void
    {
        for ($d = Carbon::now()->startOfDay(); $d->lte(Carbon::now()->addDays(30)); $d->addDay()) {
            $this->turno($prof, $d->format('Y-m-d').' 12:00:00', $duracion);
        }
    }

    public function test_sin_el_parametro_no_agrega_el_campo(): void
    {
        $s = $this->crearServicio($this->user, 'S', 45, true, $this->ana);
        $this->crearSlot($this->user, $this->ana, '12:00');

        $this->assertArrayNotHasKey('sin_turnos', $this->listado('')[$s->id]);
    }

    public function test_servicio_con_un_inicio_libre_no_esta_sin_turnos(): void
    {
        $s = $this->crearServicio($this->user, 'S', 45, true, $this->ana);
        $this->crearSlot($this->user, $this->ana, '12:00');

        $this->assertFalse($this->listado()[$s->id]['sin_turnos']);
    }

    public function test_agenda_llena_toda_la_ventana_marca_sin_turnos(): void
    {
        $s = $this->crearServicio($this->user, 'S', 45, true, $this->ana);
        $this->crearSlot($this->user, $this->ana, '12:00');
        $this->llenarAgenda($this->ana);

        $this->assertTrue($this->listado()[$s->id]['sin_turnos']);
    }

    public function test_si_queda_un_solo_dia_libre_no_esta_sin_turnos(): void
    {
        $s = $this->crearServicio($this->user, 'S', 45, true, $this->ana);
        $this->crearSlot($this->user, $this->ana, '12:00');
        $this->llenarAgenda($this->ana);
        Turno::where('fecha_hora', 'like', '2099-06-20%')->delete();

        $this->assertFalse($this->listado()[$s->id]['sin_turnos']);
    }

    public function test_la_duracion_que_no_entra_antes_del_siguiente_turno_marca_sin_turnos(): void
    {
        // Slots 12:00 y 12:30 con turnos a las 12:30 todos los dias: 90 min no entran desde las 12:00.
        $largo = $this->crearServicio($this->user, 'Largo', 90, true, $this->ana);
        $this->crearSlot($this->user, $this->ana, '12:00');
        for ($d = Carbon::now()->startOfDay(); $d->lte(Carbon::now()->addDays(30)); $d->addDay()) {
            $this->turno($this->ana, $d->format('Y-m-d').' 12:30:00', 30);
        }

        $this->assertTrue($this->listado()[$largo->id]['sin_turnos']);
    }

    public function test_bloqueo_de_dia_completo_en_toda_la_ventana_marca_sin_turnos(): void
    {
        $s = $this->crearServicio($this->user, 'S', 45, true, $this->ana);
        $this->crearSlot($this->user, $this->ana, '12:00');
        for ($d = Carbon::now()->startOfDay(); $d->lte(Carbon::now()->addDays(30)); $d->addDay()) {
            BloqueoAgenda::create(['user_id' => $this->user->id, 'profesional_id' => null, 'fecha' => $d->format('Y-m-d')]);
        }

        $this->assertTrue($this->listado()[$s->id]['sin_turnos']);
    }

    public function test_el_hold_vivo_ocupa_el_inicio(): void
    {
        $s = $this->crearServicio($this->user, 'S', 45, true, $this->ana);
        $this->crearSlot($this->user, $this->ana, '12:00');
        for ($d = Carbon::now()->startOfDay(); $d->lte(Carbon::now()->addDays(30)); $d->addDay()) {
            ReservaWeb::create([
                'user_id' => $this->user->id, 'profesional_id' => $this->ana->id, 'public_token' => ReservaWeb::generarToken(),
                'servicio_ids' => [$s->id], 'fecha' => $d->format('Y-m-d'), 'slot_hora' => '12:00',
                'duracion_total_minutos' => 45, 'estado' => 'held', 'expira_en' => Carbon::now()->timestamp + 600,
            ]);
        }

        $this->assertTrue($this->listado()[$s->id]['sin_turnos']);
    }

    public function test_con_dos_profesionales_alcanza_con_que_una_tenga_lugar(): void
    {
        $laura = $this->crearProfesional($this->user, 'Laura');
        $s = $this->crearServicio($this->user, 'S', 45, true, $this->ana);
        $laura->servicios()->attach($s->id);
        $this->crearSlot($this->user, $this->ana, '12:00');
        $this->crearSlot($this->user, $laura, '12:00');
        $this->llenarAgenda($this->ana);

        $this->assertFalse($this->listado()[$s->id]['sin_turnos']);
    }

    public function test_la_promo_exacta_esta_sin_turnos_si_un_componente_no_tiene_lugar(): void
    {
        $laura = $this->crearProfesional($this->user, 'Laura');
        $a = $this->crearServicio($this->user, 'A', 60, true, $this->ana);
        $b = $this->crearServicio($this->user, 'B', 45, true, $laura);
        $this->crearSlot($this->user, $this->ana, '10:00');
        $this->crearSlot($this->user, $laura, '11:00');
        $promo = Servicio::create([
            'user_id' => $this->user->id, 'nombre' => 'Promo', 'duracion_minutos' => 105,
            'precio' => 22000, 'activo' => true, 'es_promo' => true, 'modo_promo' => 'secuencia',
        ]);
        $promo->componentes()->create(['componente_servicio_id' => $a->id, 'profesional_id' => $this->ana->id, 'orden' => 1]);
        $promo->componentes()->create(['componente_servicio_id' => $b->id, 'profesional_id' => $laura->id, 'orden' => 2]);

        $this->assertFalse($this->listado()[$promo->id]['sin_turnos']);

        // Laura ocupada a las 11:00 todos los dias: la promo ya no se puede concretar.
        for ($d = Carbon::now()->startOfDay(); $d->lte(Carbon::now()->addDays(30)); $d->addDay()) {
            $this->turno($laura, $d->format('Y-m-d').' 11:00:00', 45);
        }
        Cache::flush();

        $this->assertTrue($this->listado()[$promo->id]['sin_turnos']);
    }

    public function test_la_cantidad_de_consultas_no_crece_con_la_cantidad_de_servicios(): void
    {
        $this->crearSlot($this->user, $this->ana, '12:00');
        $this->crearSlot($this->user, $this->ana, '15:00');
        foreach (range(1, 3) as $i) {
            $this->crearServicio($this->user, "S{$i}", 30 * $i, true, $this->ana);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->listado();
        $pocos = count(DB::getQueryLog());

        foreach (range(4, 25) as $i) {
            $this->crearServicio($this->user, "S{$i}", 15 + $i, true, $this->ana);
        }
        Cache::flush();
        DB::flushQueryLog();
        $this->listado();
        $muchos = count(DB::getQueryLog());

        // Los servicios extra agregan consultas solo por el listado en si (fotos/componentes
        // se cargan con eager loading), nunca por el calculo de disponibilidad.
        $this->assertLessThanOrEqual($pocos + 2, $muchos);
    }

    public function test_el_resultado_se_cachea_unos_segundos(): void
    {
        $s = $this->crearServicio($this->user, 'S', 45, true, $this->ana);
        $this->crearSlot($this->user, $this->ana, '12:00');
        $this->assertFalse($this->listado()[$s->id]['sin_turnos']);

        $this->llenarAgenda($this->ana);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertFalse($this->listado()[$s->id]['sin_turnos'], 'dentro del TTL no recalcula');

        Cache::flush();
        $this->assertTrue($this->listado()[$s->id]['sin_turnos']);
    }

    public function test_con_profesional_id_no_agrega_el_campo(): void
    {
        $s = $this->crearServicio($this->user, 'S', 45, true, $this->ana);
        $this->crearSlot($this->user, $this->ana, '12:00');

        $item = $this->listado("?con_turnos=1&profesional_id={$this->ana->id}")[$s->id];
        $this->assertArrayNotHasKey('sin_turnos', $item);
    }
}

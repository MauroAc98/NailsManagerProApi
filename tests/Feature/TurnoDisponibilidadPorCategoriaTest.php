<?php

namespace Tests\Feature;

use App\Models\CategoriaServicio;
use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnoDisponibilidadPorCategoriaTest extends TestCase
{
    use RefreshDatabase;

    private const FECHA = '2099-06-10';

    private User $user;
    private Profesional $ana;
    private Profesional $laura;
    private CategoriaServicio $manicura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_exempt' => true]);
        $this->ana = $this->prof($this->user, 'Ana');
        $this->laura = $this->prof($this->user, 'Laura');
        $this->manicura = CategoriaServicio::create(['user_id' => $this->user->id, 'nombre' => 'Manicura']);
    }

    private function prof(User $u, string $nombre, bool $activo = true): Profesional
    {
        return Profesional::create(['user_id' => $u->id, 'nombre' => $nombre, 'activo' => $activo]);
    }

    private function slots(Profesional $p, array $horas): void
    {
        foreach ($horas as $h) {
            SlotDisponible::create(['user_id' => $p->user_id, 'profesional_id' => $p->id, 'hora' => $h, 'activo' => true]);
        }
    }

    private function servicioDe(Profesional $p, CategoriaServicio $c): Servicio
    {
        $s = Servicio::create([
            'user_id' => $p->user_id, 'nombre' => 'Srv '.$p->nombre.$c->id, 'duracion_minutos' => 60,
            'precio' => 100, 'activo' => true, 'categoria_id' => $c->id,
        ]);
        $p->servicios()->attach($s->id);

        return $s;
    }

    private function consultar(array $extra = [])
    {
        return $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/turnos/disponibilidad?'.http_build_query(['desde' => self::FECHA, 'hasta' => self::FECHA] + $extra));
    }

    public function test_une_los_slots_de_los_profesionales_de_la_categoria_ordenados_por_hora_real(): void
    {
        $this->slots($this->ana, ['09:00:00', '10:00:00']);
        $this->slots($this->laura, ['09:00:00', '09:30:00']);
        $this->servicioDe($this->ana, $this->manicura);
        $this->servicioDe($this->laura, $this->manicura);

        $res = $this->consultar(['categoria_id' => $this->manicura->id])->assertOk()->json();

        $this->assertCount(1, $res);
        $this->assertSame(self::FECHA, $res[0]['fecha']);
        $this->assertSame(['09hs', '09:30hs', '10hs'], array_column($res[0]['slots'], 'hora'));
    }

    public function test_orden_cronologico_y_no_alfabetico(): void
    {
        $this->slots($this->ana, ['09:00:00', '10:00:00', '18:00:00']);
        $this->servicioDe($this->ana, $this->manicura);

        $res = $this->consultar(['categoria_id' => $this->manicura->id])->assertOk()->json();

        $this->assertSame(['09hs', '10hs', '18hs'], array_column($res[0]['slots'], 'hora'));
    }

    public function test_un_horario_es_libre_si_esta_libre_para_al_menos_un_profesional(): void
    {
        $this->slots($this->ana, ['09:00:00', '10:00:00']);
        $this->slots($this->laura, ['09:00:00', '10:00:00']);
        $this->servicioDe($this->ana, $this->manicura);
        $this->servicioDe($this->laura, $this->manicura);

        // Ana ocupada a las 9; Laura ocupada a las 9 y a las 10.
        $cliente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'C', 'apellido' => 'D', 'telefono' => '3765252395']);
        foreach ([[$this->ana, '09:00:00'], [$this->laura, '09:00:00'], [$this->laura, '10:00:00']] as [$p, $h]) {
            Turno::create([
                'user_id' => $this->user->id, 'cliente_id' => $cliente->id, 'profesional_id' => $p->id,
                'fecha_hora' => self::FECHA.' '.$h, 'duracion_total_minutos' => 30, 'estado' => 'confirmado',
                'origen' => 'app',
            ]);
        }

        $res = $this->consultar(['categoria_id' => $this->manicura->id])->assertOk()->json();
        $mapa = collect($res[0]['slots'])->pluck('libre', 'hora')->all();

        $this->assertSame(['09hs' => false, '10hs' => true], $mapa);
    }

    public function test_excluye_profesional_sin_servicios_de_la_categoria(): void
    {
        $otra = CategoriaServicio::create(['user_id' => $this->user->id, 'nombre' => 'Pedicura']);
        $this->slots($this->ana, ['09:00:00']);
        $this->slots($this->laura, ['11:00:00']);
        $this->servicioDe($this->ana, $this->manicura);
        $this->servicioDe($this->laura, $otra);

        $res = $this->consultar(['categoria_id' => $this->manicura->id])->assertOk()->json();

        $this->assertSame(['09hs'], array_column($res[0]['slots'], 'hora'));
    }

    public function test_excluye_profesional_inactivo(): void
    {
        $inactiva = $this->prof($this->user, 'Inactiva', false);
        $this->slots($this->ana, ['09:00:00']);
        $this->slots($inactiva, ['11:00:00']);
        $this->servicioDe($this->ana, $this->manicura);
        $this->servicioDe($inactiva, $this->manicura);

        $res = $this->consultar(['categoria_id' => $this->manicura->id])->assertOk()->json();

        $this->assertSame(['09hs'], array_column($res[0]['slots'], 'hora'));
    }

    public function test_sin_profesionales_que_coincidan_devuelve_lista_vacia(): void
    {
        $this->slots($this->ana, ['09:00:00']);

        $this->consultar(['categoria_id' => $this->manicura->id])->assertOk()->assertExactJson([]);
    }

    public function test_categoria_ajena_se_rechaza(): void
    {
        $otro = User::factory()->create(['is_exempt' => true]);
        $ajena = CategoriaServicio::create(['user_id' => $otro->id, 'nombre' => 'Ajena']);

        $this->consultar(['categoria_id' => $ajena->id])->assertStatus(404);
    }

    public function test_con_categoria_se_ignora_profesional_id(): void
    {
        $this->slots($this->ana, ['09:00:00']);
        $this->slots($this->laura, ['11:00:00']);
        $this->servicioDe($this->ana, $this->manicura);

        $res = $this->consultar(['categoria_id' => $this->manicura->id, 'profesional_id' => $this->laura->id])
            ->assertOk()->json();

        $this->assertSame(['09hs'], array_column($res[0]['slots'], 'hora'));
    }

    public function test_sin_categoria_sigue_usando_un_solo_profesional(): void
    {
        $this->slots($this->ana, ['09:00:00']);
        $this->slots($this->laura, ['11:00:00']);

        $res = $this->consultar(['profesional_id' => $this->laura->id])->assertOk()->json();

        $this->assertSame(['11hs'], array_column($res[0]['slots'], 'hora'));
    }
}

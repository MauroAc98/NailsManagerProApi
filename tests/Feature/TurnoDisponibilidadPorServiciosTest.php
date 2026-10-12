<?php

namespace Tests\Feature;

use App\Models\CategoriaServicio;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnoDisponibilidadPorServiciosTest extends TestCase
{
    use RefreshDatabase;

    private const FECHA = '2099-06-10';

    private User $user;
    private Profesional $ana;
    private Profesional $laura;
    private CategoriaServicio $depilacion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_exempt' => true]);
        $this->ana = $this->prof($this->user, 'Ana');
        $this->laura = $this->prof($this->user, 'Laura');
        $this->depilacion = CategoriaServicio::create(['user_id' => $this->user->id, 'nombre' => 'Depilación']);
        $this->slots($this->ana, ['09:00:00']);
        $this->slots($this->laura, ['11:00:00']);
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

    private function servicio(string $nombre, User $u = null): Servicio
    {
        return Servicio::create([
            'user_id' => ($u ?? $this->user)->id, 'nombre' => $nombre, 'duracion_minutos' => 30,
            'precio' => 100, 'activo' => true, 'categoria_id' => $this->depilacion->id,
        ]);
    }

    private function consultar(array $extra = [])
    {
        return $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/turnos/disponibilidad?'.http_build_query(['desde' => self::FECHA, 'hasta' => self::FECHA] + $extra));
    }

    private function horas($res): array
    {
        return array_column($res->assertOk()->json()[0]['slots'] ?? [], 'hora');
    }

    public function test_une_los_profesionales_que_hacen_alguno_de_los_servicios(): void
    {
        $piernas = $this->servicio('Cera piernas');
        $axilas = $this->servicio('Axilas');
        $this->ana->servicios()->attach($piernas->id);
        $this->laura->servicios()->attach($axilas->id);

        $this->assertSame(['09hs', '11hs'], $this->horas($this->consultar(['servicio_ids' => [$piernas->id, $axilas->id]])));
    }

    public function test_excluye_profesional_que_no_hace_ninguno_de_los_servicios(): void
    {
        $piernas = $this->servicio('Cera piernas');
        $axilas = $this->servicio('Axilas');
        $this->ana->servicios()->attach($piernas->id);
        $this->laura->servicios()->attach($axilas->id);

        $this->assertSame(['09hs'], $this->horas($this->consultar(['servicio_ids' => [$piernas->id]])));
    }

    public function test_excluye_profesional_inactivo(): void
    {
        $piernas = $this->servicio('Cera piernas');
        $inactiva = $this->prof($this->user, 'Inactiva', false);
        $this->slots($inactiva, ['15:00:00']);
        $this->ana->servicios()->attach($piernas->id);
        $inactiva->servicios()->attach($piernas->id);

        $this->assertSame(['09hs'], $this->horas($this->consultar(['servicio_ids' => [$piernas->id]])));
    }

    public function test_con_profesional_se_limita_a_ese_profesional(): void
    {
        $piernas = $this->servicio('Cera piernas');
        $this->ana->servicios()->attach($piernas->id);
        $this->laura->servicios()->attach($piernas->id);

        $res = $this->consultar(['servicio_ids' => [$piernas->id], 'profesional_id' => $this->laura->id]);

        $this->assertSame(['11hs'], $this->horas($res));
    }

    public function test_con_profesional_que_no_hace_los_servicios_devuelve_lista_vacia(): void
    {
        $piernas = $this->servicio('Cera piernas');
        $this->ana->servicios()->attach($piernas->id);

        $this->consultar(['servicio_ids' => [$piernas->id], 'profesional_id' => $this->laura->id])
            ->assertOk()->assertExactJson([]);
    }

    public function test_servicio_ajeno_se_rechaza(): void
    {
        $propio = $this->servicio('Cera piernas');
        $otro = User::factory()->create(['is_exempt' => true]);
        $ajeno = $this->servicio('Ajeno', $otro);

        $this->consultar(['servicio_ids' => [$propio->id, $ajeno->id]])->assertStatus(404);
    }

    public function test_servicio_ids_debe_ser_lista_de_enteros(): void
    {
        $this->consultar(['servicio_ids' => 'abc'])->assertStatus(422);
        $this->consultar(['servicio_ids' => ['x']])->assertStatus(422);
    }
}

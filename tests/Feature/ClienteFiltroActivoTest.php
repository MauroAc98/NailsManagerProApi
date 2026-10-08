<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClienteFiltroActivoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_exempt' => true]);

        foreach ([['Ana', true], ['Beatriz', true], ['Carla', false]] as $i => [$nombre, $activo]) {
            Cliente::create([
                'user_id' => $this->user->id,
                'nombre' => $nombre,
                'apellido' => 'Perez',
                'telefono' => '+54937611111'.$i,
                'activo' => $activo,
            ]);
        }
    }

    private function nombres(string $query): array
    {
        return collect($this->actingAs($this->user, 'sanctum')
            ->getJson("/api/clientes?page=1&{$query}")
            ->assertOk()
            ->json('data'))->pluck('nombre')->all();
    }

    public function test_sin_filtro_devuelve_activos_e_inactivos(): void
    {
        $this->assertSame(['Ana', 'Beatriz', 'Carla'], $this->nombres(''));
    }

    public function test_activo_1_devuelve_solo_los_activos(): void
    {
        $this->assertSame(['Ana', 'Beatriz'], $this->nombres('activo=1'));
    }

    public function test_activo_0_devuelve_solo_los_inactivos(): void
    {
        $this->assertSame(['Carla'], $this->nombres('activo=0'));
    }

    public function test_el_total_de_la_paginacion_respeta_el_filtro(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/clientes?page=1&activo=1')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_un_valor_invalido_se_rechaza(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/clientes?page=1&activo=quizas')
            ->assertStatus(422);
    }
}

<?php

namespace Tests\Feature;

use App\Models\CategoriaServicio;
use App\Models\Servicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class PublicServiciosTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    public function test_lista_solo_servicios_activos_con_el_shape_publico(): void
    {
        $user = $this->crearSalon();
        $activo = $this->crearServicio($user, 'Esmaltado', 45);
        $this->crearServicio($user, 'Oculto', 30, false);

        $this->getJson("/api/public/{$user->slug}/servicios")
            ->assertOk()
            ->assertExactJson([
                ['id' => $activo->id, 'nombre' => 'Esmaltado', 'duracion_minutos' => 45, 'precio' => 12000, 'categoria' => null],
            ]);
    }

    public function test_no_incluye_servicios_de_otro_salon(): void
    {
        $user = $this->crearSalon();
        $otro = $this->crearSalon();
        $this->crearServicio($otro, 'Ajeno');
        $propio = $this->crearServicio($user, 'Propio');

        $this->getJson("/api/public/{$user->slug}/servicios")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $propio->id);
    }

    public function test_incluye_la_categoria_del_servicio_o_null(): void
    {
        $user = $this->crearSalon();
        $cat = CategoriaServicio::create(['user_id' => $user->id, 'nombre' => 'Manicura']);
        $con = $this->crearServicio($user, 'Con');
        $con->update(['categoria_id' => $cat->id]);
        $sin = $this->crearServicio($user, 'Sin');

        $res = $this->getJson("/api/public/{$user->slug}/servicios")->assertOk();
        $res->assertJsonPath('0.id', $con->id)
            ->assertJsonPath('0.categoria', ['id' => $cat->id, 'nombre' => 'Manicura'])
            ->assertJsonPath('1.id', $sin->id)
            ->assertJsonPath('1.categoria', null);
    }

    public function test_no_filtra_categorias_de_otro_salon(): void
    {
        $user = $this->crearSalon();
        $otro = $this->crearSalon();
        $ajena = CategoriaServicio::create(['user_id' => $otro->id, 'nombre' => 'Ajena']);
        $s = $this->crearServicio($user, 'Propio');
        // Dato corrupto: servicio apuntando a categoria de otro salon.
        Servicio::where('id', $s->id)->update(['categoria_id' => $ajena->id]);

        $res = $this->getJson("/api/public/{$user->slug}/servicios")->assertOk();
        $res->assertJsonPath('0.categoria', null);
        $this->assertStringNotContainsString('Ajena', $res->getContent());
    }

    public function test_ordena_por_categoria_alfabetica_luego_orden_y_sin_categoria_al_final(): void
    {
        $user = $this->crearSalon();
        $pedi = CategoriaServicio::create(['user_id' => $user->id, 'nombre' => 'Pedicura']);
        $mani = CategoriaServicio::create(['user_id' => $user->id, 'nombre' => 'Manicura']);
        $sin  = $this->crearServicio($user, 'Zeta');
        $p    = $this->crearServicio($user, 'P');
        $m2   = $this->crearServicio($user, 'M2');
        $m1   = $this->crearServicio($user, 'M1');
        $p->update(['categoria_id' => $pedi->id, 'orden' => 0]);
        $m2->update(['categoria_id' => $mani->id, 'orden' => 1]);
        $m1->update(['categoria_id' => $mani->id, 'orden' => 0]);

        $ids = collect($this->getJson("/api/public/{$user->slug}/servicios")->assertOk()->json())->pluck('id')->all();

        $this->assertSame([$m1->id, $m2->id, $p->id, $sin->id], $ids);
    }

    public function test_filtra_por_profesional_via_pivot(): void
    {
        $user = $this->crearSalon();
        $ana = $this->crearProfesional($user, 'Ana');
        $bea = $this->crearProfesional($user, 'Bea');
        $x = $this->crearServicio($user, 'X', 30, true, $ana);
        $y = $this->crearServicio($user, 'Y', 30, true, $bea);

        $this->getJson("/api/public/{$user->slug}/servicios?profesional_id={$ana->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $x->id);
    }

    public function test_profesional_de_otro_salon_da_404(): void
    {
        $user = $this->crearSalon();
        $otro = $this->crearSalon();
        $ajena = $this->crearProfesional($otro, 'Ajena');

        $this->getJson("/api/public/{$user->slug}/servicios?profesional_id={$ajena->id}")->assertNotFound();
    }

    public function test_profesional_inactiva_da_404(): void
    {
        $user = $this->crearSalon();
        $inactiva = $this->crearProfesional($user, 'Inactiva', false);

        $this->getJson("/api/public/{$user->slug}/servicios?profesional_id={$inactiva->id}")->assertNotFound();
    }

    public function test_salon_con_suscripcion_vencida_da_404(): void
    {
        $user = $this->crearSalon(['is_exempt' => false]);
        $this->crearSuscripcion($user, 'VENCIDO', now()->subDay());

        $this->getJson("/api/public/{$user->slug}/servicios")->assertNotFound();
    }
}

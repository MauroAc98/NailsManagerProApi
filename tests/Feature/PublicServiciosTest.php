<?php

namespace Tests\Feature;

use App\Models\CategoriaServicio;
use App\Models\Servicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class PublicServiciosTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    // Un servicio solo se lista si alguna profesional activa lo ofrece: estos tests
    // no hablan de profesionales, asi que los ofrece una del propio salon.
    private function servicioOfrecido(\App\Models\User $user, string $nombre, int $duracion = 45, bool $activo = true, ?\App\Models\Profesional $profesional = null): \App\Models\Servicio
    {
        $profesional ??= \App\Models\Profesional::where('user_id', $user->id)->where('activo', true)->first()
            ?? $this->crearProfesional($user, 'Ana');

        // ...y que tenga horarios cargados: sin slots nunca tendria un turno libre.
        if (! \App\Models\SlotDisponible::where('profesional_id', $profesional->id)->exists()) {
            $this->crearSlot($user, $profesional, '10:00');
        }

        return $this->crearServicio($user, $nombre, $duracion, $activo, $profesional);
    }

    public function test_no_lista_un_servicio_que_solo_ofrece_una_profesional_sin_horarios(): void
    {
        $user = $this->crearSalon();
        $sinHorarios = $this->crearProfesional($user, 'Sin horarios');
        $servicio = $this->crearServicio($user, 'Sin turnos posibles', 30, true, $sinHorarios);

        $ids = collect($this->getJson("/api/public/{$user->slug}/servicios")->assertOk()->json())->pluck('id');

        $this->assertFalse($ids->contains($servicio->id));
    }

    public function test_los_slots_legacy_sin_profesional_cuentan_para_la_activa_mas_antigua(): void
    {
        $user = $this->crearSalon();
        $primera = $this->crearProfesional($user, 'Primera');
        $segunda = $this->crearProfesional($user, 'Segunda');
        $this->crearSlot($user, null, '10:00');
        $deLaPrimera = $this->crearServicio($user, 'De la primera', 30, true, $primera);
        $deLaSegunda = $this->crearServicio($user, 'De la segunda', 30, true, $segunda);

        $ids = collect($this->getJson("/api/public/{$user->slug}/servicios")->assertOk()->json())->pluck('id');

        $this->assertTrue($ids->contains($deLaPrimera->id));
        $this->assertFalse($ids->contains($deLaSegunda->id));
    }

    public function test_no_lista_un_servicio_que_ninguna_profesional_ofrece(): void
    {
        $user = $this->crearSalon();
        $this->crearProfesional($user, 'Ana');
        $huerfano = $this->crearServicio($user, 'Nadie lo hace', 30);
        $ofrecido = $this->servicioOfrecido($user, 'Lo hace Ana', 30);

        $ids = collect($this->getJson("/api/public/{$user->slug}/servicios")->assertOk()->json())->pluck('id');

        $this->assertTrue($ids->contains($ofrecido->id));
        $this->assertFalse($ids->contains($huerfano->id));
    }

    public function test_no_lista_un_servicio_que_solo_ofrece_una_profesional_inactiva(): void
    {
        $user = $this->crearSalon();
        $inactiva = $this->crearProfesional($user, 'Vieja', false);
        $servicio = $this->crearServicio($user, 'Solo la inactiva', 30, true, $inactiva);

        $ids = collect($this->getJson("/api/public/{$user->slug}/servicios")->assertOk()->json())->pluck('id');

        $this->assertFalse($ids->contains($servicio->id));
    }

    public function test_no_lista_una_promo_legacy_sin_componentes_que_nadie_ofrece(): void
    {
        $user = $this->crearSalon();
        $this->crearProfesional($user, 'Ana');
        $promo = \App\Models\Servicio::create([
            'user_id' => $user->id, 'nombre' => 'Promo vieja', 'duracion_minutos' => 60,
            'precio' => 9000, 'activo' => true, 'es_promo' => true,
        ]);

        $ids = collect($this->getJson("/api/public/{$user->slug}/servicios")->assertOk()->json())->pluck('id');

        $this->assertFalse($ids->contains($promo->id));
    }

    public function test_lista_solo_servicios_activos_con_el_shape_publico(): void
    {
        $user = $this->crearSalon();
        $activo = $this->servicioOfrecido($user, 'Esmaltado', 45);
        $this->servicioOfrecido($user, 'Oculto', 30, false);

        $this->getJson("/api/public/{$user->slug}/servicios")
            ->assertOk()
            ->assertExactJson([
                ['id' => $activo->id, 'nombre' => 'Esmaltado', 'duracion_minutos' => 45, 'precio' => 12000, 'es_promo_componentizada' => false, 'categoria' => null, 'fotos' => []],
            ]);
    }

    public function test_incluye_las_fotos_del_servicio_ordenadas_como_urls(): void
    {
        Storage::fake('public');

        $user = $this->crearSalon();
        $servicio = $this->servicioOfrecido($user, 'Esmaltado', 45);
        $servicio->fotos()->create(['path' => 'servicio_fotos/b.jpg', 'orden' => 1]);
        $servicio->fotos()->create(['path' => 'servicio_fotos/a.jpg', 'orden' => 0]);

        $res = $this->getJson("/api/public/{$user->slug}/servicios")->assertOk();

        $res->assertJsonPath('0.fotos', [
            Storage::disk('public')->url('servicio_fotos/a.jpg'),
            Storage::disk('public')->url('servicio_fotos/b.jpg'),
        ]);
        // El array de fotos son URLs planas (strings), nunca objetos con
        // 'id'/'path' — no hay forma de que el 'id' interno de la fila se
        // filtre en la respuesta pública.
        $this->assertIsString($res->json('0.fotos.0'));
    }

    public function test_no_incluye_servicios_de_otro_salon(): void
    {
        $user = $this->crearSalon();
        $otro = $this->crearSalon();
        $this->crearServicio($otro, 'Ajeno');
        $propio = $this->servicioOfrecido($user, 'Propio');

        $this->getJson("/api/public/{$user->slug}/servicios")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $propio->id);
    }

    public function test_incluye_la_categoria_del_servicio_o_null(): void
    {
        $user = $this->crearSalon();
        $cat = CategoriaServicio::create(['user_id' => $user->id, 'nombre' => 'Manicura']);
        $con = $this->servicioOfrecido($user, 'Con');
        $con->update(['categoria_id' => $cat->id]);
        $sin = $this->servicioOfrecido($user, 'Sin');

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
        $s = $this->servicioOfrecido($user, 'Propio');
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
        $sin  = $this->servicioOfrecido($user, 'Zeta');
        $p    = $this->servicioOfrecido($user, 'P');
        $m2   = $this->servicioOfrecido($user, 'M2');
        $m1   = $this->servicioOfrecido($user, 'M1');
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
        $x = $this->servicioOfrecido($user, 'X', 30, true, $ana);
        $y = $this->servicioOfrecido($user, 'Y', 30, true, $bea);

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

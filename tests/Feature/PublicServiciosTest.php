<?php

namespace Tests\Feature;

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
                ['id' => $activo->id, 'nombre' => 'Esmaltado', 'duracion_minutos' => 45, 'precio' => 12000],
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

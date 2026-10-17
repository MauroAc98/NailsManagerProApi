<?php

namespace Tests\Unit;

use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * AM-02 / D2: the persona titular's own profile is, by construction, the FIRST
 * professional of the negocio (lowest id). There is no flag column: the rule
 * is derived, so these tests pin the derivation.
 */
class ProfesionalEsPerfilTitularTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    public function test_the_lowest_id_professional_of_a_negocio_is_the_titular_profile(): void
    {
        $negocio = $this->crearNegocioPortal();

        $this->assertTrue($negocio['titular']->esPerfilTitular());
    }

    public function test_the_other_professionals_of_the_negocio_are_not_the_titular_profile(): void
    {
        $negocio = $this->crearNegocioPortal();

        $this->assertFalse($negocio['ana']->esPerfilTitular());
        $this->assertFalse($negocio['bea']->esPerfilTitular());
    }

    public function test_a_deactivated_first_professional_is_still_the_titular_profile(): void
    {
        $negocio = $this->crearNegocioPortal();
        $negocio['titular']->update(['activo' => false]);

        $this->assertTrue($negocio['titular']->fresh()->esPerfilTitular());
        $this->assertFalse($negocio['ana']->fresh()->esPerfilTitular());
    }

    public function test_the_rule_is_resolved_per_negocio_not_globally(): void
    {
        $a = $this->crearNegocioPortal();
        $b = $this->crearNegocioPortal();

        // Negocio B's titular has a higher id than every professional of A.
        $this->assertGreaterThan($a['bea']->id, $b['titular']->id);
        $this->assertTrue($b['titular']->esPerfilTitular());
        $this->assertTrue($a['titular']->esPerfilTitular());
        $this->assertFalse($b['ana']->esPerfilTitular());
    }

    public function test_a_professional_created_later_in_a_one_professional_negocio_is_not_the_titular(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $primera = $this->crearProfesionalPortal($user, 'Primera');
        $segunda = $this->crearProfesionalPortal($user, 'Segunda');

        $this->assertTrue($primera->esPerfilTitular());
        $this->assertFalse($segunda->esPerfilTitular());
    }

    public function test_it_reflects_the_current_state_of_the_database(): void
    {
        // The first professional is removed (e.g. by hand): the next one in id
        // order becomes the lowest id. The method must not cache.
        $negocio = $this->crearNegocioPortal();
        $this->assertFalse($negocio['ana']->esPerfilTitular());

        Profesional::whereKey($negocio['titular']->id)->delete();

        $this->assertTrue($negocio['ana']->esPerfilTitular());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

// GET /api/public/{slug}/servicios con promos componentizadas: el listado marca
// las promos de profesional fija y esconde las que no se pueden reservar.
class PublicServiciosPromoTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    private User $user;
    private Profesional $ana;
    private Profesional $laura;
    private Servicio $softgel;
    private Servicio $semis;
    private Servicio $promo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->crearSalon();
        $this->ana = $this->crearProfesional($this->user, 'Ana');
        $this->laura = $this->crearProfesional($this->user, 'Laura');
        $this->softgel = $this->crearServicio($this->user, 'Softgel', 60, true, $this->ana);
        $this->semis = $this->crearServicio($this->user, 'Semis pies', 45, true, $this->laura);
        $this->promo = Servicio::create([
            'user_id' => $this->user->id, 'nombre' => 'Promo', 'duracion_minutos' => 105,
            'precio' => 22000, 'activo' => true, 'es_promo' => true, 'modo_promo' => 'secuencia',
        ]);
        $this->promo->componentes()->create(['componente_servicio_id' => $this->softgel->id, 'profesional_id' => $this->ana->id, 'orden' => 1]);
        $this->promo->componentes()->create(['componente_servicio_id' => $this->semis->id, 'profesional_id' => $this->laura->id, 'orden' => 2]);
    }

    private function listado(): array
    {
        return collect($this->getJson("/api/public/{$this->user->slug}/servicios")->assertOk()->json())->keyBy('id')->all();
    }

    public function test_la_promo_con_componentes_se_marca_de_profesional_fija(): void
    {
        $lista = $this->listado();

        $this->assertTrue($lista[$this->promo->id]['es_promo_componentizada']);
        $this->assertFalse($lista[$this->softgel->id]['es_promo_componentizada']);
    }

    public function test_una_promo_legacy_sin_componentes_no_se_marca(): void
    {
        $legacy = Servicio::create([
            'user_id' => $this->user->id, 'nombre' => 'Promo vieja', 'duracion_minutos' => 60,
            'precio' => 9000, 'activo' => true, 'es_promo' => true,
        ]);

        $this->assertFalse($this->listado()[$legacy->id]['es_promo_componentizada']);
    }

    public function test_la_promo_no_se_lista_si_una_profesional_componente_esta_inactiva(): void
    {
        $this->laura->update(['activo' => false]);

        $this->assertArrayNotHasKey($this->promo->id, $this->listado());
    }

    public function test_la_promo_no_se_lista_si_una_componente_ya_no_la_ofrece_su_profesional(): void
    {
        $this->laura->servicios()->detach($this->semis->id);

        $this->assertArrayNotHasKey($this->promo->id, $this->listado());
    }

    public function test_la_promo_no_se_lista_si_un_servicio_componente_esta_inactivo(): void
    {
        $this->semis->update(['activo' => false]);

        $this->assertArrayNotHasKey($this->promo->id, $this->listado());
    }
}

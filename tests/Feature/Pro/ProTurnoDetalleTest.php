<?php

namespace Tests\Feature\Pro;

use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
use App\Models\TurnoGrupo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * GET /api/pro/turnos/{id} (AG-07). One shape for "hers"; one identical 404
 * for everything else, so an id reveals nothing about turnos of other people.
 */
class ProTurnoDetalleTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private array $negocio;

    private ProfesionalCuenta $cuenta;

    private ProfesionalVinculo $vinculo;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 12:00:00');
        $this->negocio = $this->crearNegocioPortal();
        $this->cuenta = $this->crearCuentaPortal();
        $this->vinculo = $this->vincularPortal($this->cuenta, $this->negocio['ana']);
        $this->token = $this->tokenPara($this->cuenta);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function detalle(string|int $id, ?int $vinculoId = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)
            ->getJson("/api/pro/turnos/{$id}", ['X-Vinculo-Id' => (string) ($vinculoId ?? $this->vinculo->id)]);
    }

    public function test_her_turno_is_returned_unwrapped_with_the_whitelisted_shape(): void
    {
        $cliente = $this->crearClientePortal($this->negocio['user']);
        $servicio = $this->crearServicioPortal($this->negocio['user']);
        // A day outside the default "today" window: the detail has no date filter.
        $turno = $this->crearTurnoPortal($this->negocio['ana'], '2026-12-01 16:00:00', ['cliente_id' => $cliente->id, 'notas' => 'Alergia al acrilico']);
        $turno->servicios()->attach($servicio->id, ['precio' => 12345]);

        $r = $this->detalle($turno->id)->assertOk();

        $this->assertSame([
            'id' => $turno->id,
            'fecha_hora' => '2026-12-01T16:00:00',
            'duracion_total_minutos' => 60,
            'estado_visual' => 'confirmado',
            'notas' => 'Alergia al acrilico',
            'cliente' => ['nombre' => 'Maria', 'apellido' => 'Lopez'],
            'servicios' => [['id' => $servicio->id, 'nombre' => 'Capping']],
            'promo' => null,
        ], $r->json());
    }

    public function test_a_completed_turno_is_visible_and_a_promo_turno_shows_only_her_step(): void
    {
        $promo = $this->crearServicioPortal($this->negocio['user'], 'Manicura + Pedicura', ['es_promo' => true]);
        $manicura = $this->crearServicioPortal($this->negocio['user'], 'Manicura');
        $spa = $this->crearServicioPortal($this->negocio['user'], 'Spa de pies');
        $grupo = TurnoGrupo::create(['modo' => 'secuencia', 'promo_servicio_id' => $promo->id]);
        $suyo = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-10 10:00:00', ['grupo_id' => $grupo->id, 'estado' => 'completado']);
        $suyo->servicios()->attach($manicura->id);
        $this->crearTurnoPortal($this->negocio['bea'], '2026-10-10 11:00:00', ['grupo_id' => $grupo->id])->servicios()->attach($spa->id);

        $r = $this->detalle($suyo->id)->assertOk();

        $this->assertSame('completado', $r->json('estado_visual'));
        $this->assertSame(['nombre' => 'Manicura + Pedicura'], $r->json('promo'));
        $this->assertSame([['id' => $manicura->id, 'nombre' => 'Manicura']], $r->json('servicios'));
        $this->assertStringNotContainsString('Spa de pies', $r->getContent());
    }

    public function test_every_turno_that_is_not_hers_answers_the_same_404_body(): void
    {
        // Hers is created first (id 1) so loose spellings of that id ("01", "1.5", "1e0") would find it.
        $suyo = $this->crearTurnoPortal($this->negocio['ana']);
        $this->assertSame(1, $suyo->id);
        $otroNegocio = $this->crearNegocioPortal();
        $deBea = $this->crearTurnoPortal($this->negocio['bea']);
        $deOtroNegocio = $this->crearTurnoPortal($otroNegocio['ana']);
        $cancelado = $this->crearTurnoPortal($this->negocio['ana'], '2026-10-17 10:00:00', ['estado' => 'cancelado']);

        $cuerpos = [];
        foreach ([$deBea->id, $deOtroNegocio->id, $cancelado->id, 99999, 'abc', '0', '-1', '1.5', '01', '1e0', '1%0A'] as $id) {
            $r = $this->detalle($id)->assertStatus(404);
            $cuerpos[(string) $id] = $r->getContent();
        }

        $this->assertCount(1, array_unique($cuerpos), 'the 404 body must not depend on why the turno is invisible');
        $this->assertSame('TURNO_NO_ENCONTRADO', json_decode(reset($cuerpos), true)['code']);
    }

    public function test_the_same_id_is_hers_or_not_depending_on_the_link_in_the_header(): void
    {
        $turno = $this->crearTurnoPortal($this->negocio['bea']);
        $vinculoBea = $this->vincularPortal($this->cuenta, $this->negocio['bea']);

        $this->detalle($turno->id, $this->vinculo->id)->assertStatus(404);
        $this->detalle($turno->id, $vinculoBea->id)->assertOk()->assertJsonPath('id', $turno->id);
    }

    public function test_a_missing_link_header_is_a_422_and_a_foreign_link_a_403(): void
    {
        $turno = $this->crearTurnoPortal($this->negocio['ana']);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson("/api/pro/turnos/{$turno->id}")
            ->assertStatus(422)->assertJsonPath('code', 'VINCULO_REQUERIDO');

        $ajena = $this->crearCuentaPortal('bea@x.com');
        $vinculoAjeno = $this->vincularPortal($ajena, $this->negocio['bea']);
        $this->detalle($turno->id, $vinculoAjeno->id)->assertStatus(403)->assertJsonPath('code', 'VINCULO_NO_DISPONIBLE');
    }

    public function test_write_methods_on_a_turno_are_not_exposed(): void
    {
        $turno = $this->crearTurnoPortal($this->negocio['ana']);
        $cabeceras = ['X-Vinculo-Id' => (string) $this->vinculo->id];

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->putJson("/api/pro/turnos/{$turno->id}", ['estado' => 'cancelado'], $cabeceras)->assertStatus(405);
        $this->withToken($this->token)->deleteJson("/api/pro/turnos/{$turno->id}", [], $cabeceras)->assertStatus(405);
        $this->withToken($this->token)->patchJson("/api/pro/turnos/{$turno->id}", ['notas' => 'x'], $cabeceras)->assertStatus(405);
        $this->assertSame('confirmado', $turno->fresh()->estado);
    }
}

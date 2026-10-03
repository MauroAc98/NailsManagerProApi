<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

/**
 * combo-multi-profesional, PR 4b0: GET reservas/{token} suma `fin`, `tramos` y
 * `profesionales` al resumen SOLO cuando la reserva es multi-tramo. El
 * resumen legacy (un tramo) queda byte-a-byte igual (Rule L; ademas lo fija
 * PublicReservasHoldsTest::test_estado_devuelve_el_contrato_con_resumen).
 */
class PublicReservaEstadoGrupoTest extends TestCase
{
    use CreaSalonPublico, RefreshDatabase;

    private const FECHA = '2099-06-11';

    private const DEVICE = 'device-token-de-prueba-0123456789abcdef';

    private User $user;

    private Profesional $ana;

    private Profesional $laura;

    private Servicio $softgel;

    private Servicio $semis;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2099-06-01 09:00:00'));
        config(['reservas.creacion_habilitada' => true]);
        $this->user = $this->crearSalon();
        $this->ana = $this->crearProfesional($this->user, 'Ana');
        $this->laura = $this->crearProfesional($this->user, 'Laura');
        $this->softgel = $this->crearServicio($this->user, 'Softgel', 60, true, $this->ana);
        $this->semis = $this->crearServicio($this->user, 'Semis', 45, true, $this->laura);
        foreach (['10:00', '11:00'] as $h) {
            $this->crearSlot($this->user, $this->ana, $h);
            $this->crearSlot($this->user, $this->laura, $h);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(string $path = ''): string
    {
        return '/api/public/'.$this->user->slug.'/reservas'.$path;
    }

    private function headers(?string $key = null): array
    {
        return ['X-Device-Token' => self::DEVICE] + ($key ? ['Idempotency-Key' => $key] : []);
    }

    private function holdGrupo(): string
    {
        return $this->postJson($this->url('/holds'), [
            'asignaciones' => [
                ['servicio_ids' => [$this->softgel->id], 'profesional_id' => $this->ana->id],
                ['servicio_ids' => [$this->semis->id], 'profesional_id' => $this->laura->id],
            ],
            'modo' => 'secuencia',
            'fecha' => self::FECHA,
            'hora' => '10:00',
        ], $this->headers('key-1'))->assertCreated()->json('token');
    }

    public function test_el_estado_de_un_grupo_devuelve_fin_tramos_y_profesionales(): void
    {
        $token = $this->holdGrupo();

        $resumen = $this->getJson($this->url("/{$token}"), $this->headers())->assertOk()->json('resumen');

        $this->assertSame('11:45', $resumen['fin']);
        $this->assertSame([
            ['profesional_id' => $this->ana->id, 'hora' => '10:00', 'fin' => '11:00', 'servicio_ids' => [$this->softgel->id]],
            ['profesional_id' => $this->laura->id, 'hora' => '11:00', 'fin' => '11:45', 'servicio_ids' => [$this->semis->id]],
        ], $resumen['tramos']);
        $this->assertSame([
            ['id' => $this->ana->id, 'nombre' => 'Ana'],
            ['id' => $this->laura->id, 'nombre' => 'Laura'],
        ], $resumen['profesionales']);
        $this->assertSame(105, $resumen['duracion_total_minutos']);
    }

    public function test_el_estado_de_una_reserva_de_un_tramo_no_trae_campos_nuevos(): void
    {
        $token = $this->postJson($this->url('/holds'), [
            'asignaciones' => [['servicio_ids' => [$this->softgel->id], 'profesional_id' => $this->ana->id]],
            'fecha' => self::FECHA,
            'hora' => '10:00',
        ], $this->headers('key-2'))->assertCreated()->json('token');

        $resumen = $this->getJson($this->url("/{$token}"), $this->headers())->assertOk()->json('resumen');

        $this->assertSame(
            ['servicio_ids', 'profesional_id', 'fecha', 'hora', 'duracion_total_minutos', 'deposito', 'nota'],
            array_keys($resumen),
        );
    }
}

<?php

namespace Tests\Feature\Combo;

use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\ServicioComponente;
use App\Models\TurnoGrupo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Contract\AdminContractTestCase;

/**
 * Additive schema for combo-multi-profesional (PR 1a). Every new column is
 * nullable or defaulted so legacy rows and code are unaffected.
 */
class ComboSchemaTest extends AdminContractTestCase
{
    private function crearComponente(): Servicio
    {
        return Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Semis', 'duracion_minutos' => 45, 'precio' => 9000, 'activo' => true]);
    }

    private function crearPromo(): Servicio
    {
        return Servicio::create([
            'user_id' => $this->user->id, 'nombre' => 'Promo', 'duracion_minutos' => 60, 'precio' => 18000,
            'activo' => true, 'es_promo' => true, 'modo_promo' => 'secuencia',
        ]);
    }

    public function test_servicio_componentes_stores_ordered_components_with_a_professional(): void
    {
        $promo = $this->crearPromo();
        $componente = $this->crearComponente();

        $fila = ServicioComponente::create([
            'servicio_id' => $promo->id,
            'componente_servicio_id' => $componente->id,
            'profesional_id' => $this->ana->id,
            'orden' => 1,
        ]);

        $this->assertSame($componente->id, $fila->componenteServicio->id);
        $this->assertSame($this->ana->id, $fila->profesional->id);
        $this->assertSame([$componente->id], $promo->componentes()->pluck('componente_servicio_id')->all());
    }

    public function test_servicio_componentes_orden_is_unique_per_promo(): void
    {
        $promo = $this->crearPromo();
        $componente = $this->crearComponente();
        $base = ['servicio_id' => $promo->id, 'componente_servicio_id' => $componente->id, 'profesional_id' => $this->ana->id];
        ServicioComponente::create($base + ['orden' => 1]);

        $this->expectException(QueryException::class);
        ServicioComponente::create($base + ['orden' => 1]);
    }

    public function test_the_same_component_service_may_repeat_in_a_promo_with_another_orden(): void
    {
        $promo = $this->crearPromo();
        $componente = $this->crearComponente();
        $base = ['servicio_id' => $promo->id, 'componente_servicio_id' => $componente->id, 'profesional_id' => $this->ana->id];

        ServicioComponente::create($base + ['orden' => 1]);
        ServicioComponente::create($base + ['orden' => 2]);

        $this->assertSame(2, $promo->componentes()->count());
    }

    public function test_deleting_the_promo_cascades_to_its_components(): void
    {
        $promo = $this->crearPromo();
        ServicioComponente::create([
            'servicio_id' => $promo->id, 'componente_servicio_id' => $this->crearComponente()->id,
            'profesional_id' => $this->ana->id, 'orden' => 1,
        ]);

        $promo->delete();

        $this->assertSame(0, ServicioComponente::count());
    }

    public function test_legacy_servicio_has_null_modo_promo(): void
    {
        $this->assertNull($this->servicio->fresh()->modo_promo);
    }

    public function test_turno_grupo_links_turnos_and_nulls_them_when_deleted(): void
    {
        $turno = $this->crearTurno();
        $this->assertNull($turno->fresh()->grupo_id, 'legacy turno has no grupo');

        $grupo = TurnoGrupo::create(['modo' => 'secuencia', 'precio_promo' => 18000]);
        $turno->update(['grupo_id' => $grupo->id]);

        $this->assertSame($grupo->id, $turno->fresh()->grupo->id);
        $this->assertSame([$turno->id], $grupo->turnos()->pluck('id')->all());

        $grupo->delete();

        $this->assertNull($turno->fresh()->grupo_id);
    }

    public function test_turno_grupo_optional_links_default_to_null(): void
    {
        $grupo = TurnoGrupo::create(['modo' => 'paralelo'])->fresh();

        $this->assertNull($grupo->promo_servicio_id);
        $this->assertNull($grupo->reserva_web_id);
        $this->assertNull($grupo->precio_promo);
    }

    public function test_sync_without_pivot_data_keeps_precio_sugerido(): void
    {
        $turno = $this->crearTurno();
        $turno->servicios()->updateExistingPivot($this->servicio->id, ['precio_sugerido' => 10636]);

        $turno->servicios()->sync([$this->servicio->id]);

        $this->assertSame(10636, (int) $turno->fresh()->servicios->first()->pivot->precio_sugerido);
    }

    public function test_reserva_web_tramos_roundtrip_as_raw_ints_without_datetime_casts(): void
    {
        $tramos = [
            ['profesional_id' => $this->ana->id, 'servicio_id' => $this->servicio->id, 'offset_minutos' => 0, 'duracion_minutos' => 60],
        ];
        $reserva = ReservaWeb::create([
            'user_id' => $this->user->id, 'nombre_completo' => 'Cli', 'telefono' => '3765252395',
            'servicio_ids' => [$this->servicio->id], 'fecha' => '2099-06-10', 'slot_hora' => '10:00:00',
            'duracion_total_minutos' => 60, 'tramos' => $tramos, 'tramos_modo' => 'secuencia',
        ]);

        $fresca = $reserva->fresh();

        $this->assertSame($tramos, $fresca->tramos);
        $this->assertSame('secuencia', $fresca->tramos_modo);
    }

    public function test_legacy_reserva_web_has_null_tramos(): void
    {
        $reserva = ReservaWeb::create([
            'user_id' => $this->user->id, 'nombre_completo' => 'Cli', 'telefono' => '3765252395',
            'servicio_ids' => [$this->servicio->id], 'fecha' => '2099-06-10', 'slot_hora' => '10:00:00',
            'duracion_total_minutos' => 30,
        ])->fresh();

        $this->assertNull($reserva->tramos);
        $this->assertNull($reserva->tramos_modo);
    }

    public function test_get_turnos_gains_additive_null_fields_and_removes_nothing(): void
    {
        $this->crearTurno();

        $turno = $this->admin()->getJson('/api/turnos')->assertOk()->json('0');

        $this->assertArrayHasKey('grupo_id', $turno);
        $this->assertNull($turno['grupo_id']);
        $this->assertArrayHasKey('precio_sugerido', $turno['servicios'][0]['pivot']);
        $this->assertNull($turno['servicios'][0]['pivot']['precio_sugerido']);
        foreach (array_keys(self::TURNO_CORE) as $clave) {
            $this->assertArrayHasKey($clave, $turno, "GET /turnos lost '{$clave}'");
        }
    }

    public function test_rollback_of_the_seven_migrations_removes_every_new_object_and_reapplies(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 7]);

        $this->assertFalse(Schema::hasTable('servicio_componentes'));
        $this->assertFalse(Schema::hasTable('turno_grupos'));
        $this->assertFalse(Schema::hasColumn('servicios', 'modo_promo'));
        $this->assertFalse(Schema::hasColumn('turnos', 'grupo_id'));
        $this->assertFalse(Schema::hasColumn('turno_servicio', 'precio_sugerido'));
        $this->assertFalse(Schema::hasColumn('reservas_web', 'tramos'));
        $this->assertFalse(Schema::hasColumn('reservas_web', 'tramos_modo'));
        $this->assertFalse(Schema::hasColumn('users', 'atiende_en_paralelo'));
        // The migration before the seven must still be applied.
        $this->assertTrue(Schema::hasColumn('pagos_sena', 'payment_type_id'));

        Artisan::call('migrate');

        $this->assertTrue(Schema::hasTable('servicio_componentes'));
        $this->assertTrue(Schema::hasColumn('users', 'atiende_en_paralelo'));
    }
}

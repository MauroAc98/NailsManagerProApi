<?php

namespace Tests\Feature\Combo;

use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\User;
use Tests\Feature\Contract\AdminContractTestCase;

/**
 * Promo components API (combo-multi-profesional, PR 2a): a promo (es_promo
 * servicio) is composed of existing services, each with a fixed professional.
 * A promo WITHOUT components keeps the legacy behavior exactly.
 */
class ServicioComponentesTest extends AdminContractTestCase
{
    protected Profesional $laura;
    protected Servicio $softgel;
    protected Servicio $semis;
    protected Servicio $promo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->laura = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Laura', 'activo' => true]);
        $this->softgel = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Softgel', 'duracion_minutos' => 60, 'precio' => 13000, 'activo' => true]);
        $this->semis = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Semis pies', 'duracion_minutos' => 45, 'precio' => 9000, 'activo' => true]);
        $this->promo = Servicio::create([
            'user_id' => $this->user->id, 'nombre' => 'Softgel + Semis pies', 'duracion_minutos' => 90,
            'precio' => 15000, 'activo' => true, 'es_promo' => true,
        ]);

        $this->ana->servicios()->attach($this->softgel->id);
        $this->laura->servicios()->attach($this->semis->id);
    }

    protected function ponerComponentes(array $payload)
    {
        return $this->admin()->putJson("/api/servicios/{$this->promo->id}/componentes", $payload);
    }

    /** Softgel (Ana) then Semis pies (Laura). */
    protected function payloadValido(array $extra = []): array
    {
        return array_merge([
            'modo_promo' => 'secuencia',
            'componentes' => [
                ['servicio_id' => $this->softgel->id, 'profesional_id' => $this->ana->id],
                ['servicio_id' => $this->semis->id, 'profesional_id' => $this->laura->id],
            ],
        ], $extra);
    }

    // 2a.1 — Rule L: a servicio without components keeps its legacy responses.
    public function test_legacy_servicio_responses_keep_every_existing_key_and_only_add_optional_ones(): void
    {
        $show = $this->admin()->getJson("/api/servicios/{$this->servicio->id}")->assertOk()->json();
        $index = $this->admin()->getJson('/api/servicios')->assertOk()->json();
        $update = $this->admin()->putJson("/api/servicios/{$this->servicio->id}", ['precio' => 1200])->assertOk()->json();

        foreach ([$show, $index[0], $update] as $json) {
            foreach (array_keys(self::SERVICIO) as $clave) {
                $this->assertArrayHasKey($clave, $json);
            }
            $this->assertArrayHasKey('modo_promo', $json);
            $this->assertNull($json['modo_promo']);
        }

        // The new GET-one keys default to "nothing configured".
        $this->assertSame([], $show['componentes']);
        $this->assertSame([], $show['problemas']);
        $this->assertNull($show['duracion_derivada']);
        $this->assertNull($show['precio_componentes']);
        $this->assertArrayNotHasKey('componentes', $index[0]);
    }

    public function test_legacy_promo_without_components_is_untouched_by_show(): void
    {
        $json = $this->admin()->getJson("/api/servicios/{$this->promo->id}")->assertOk()->json();

        $this->assertSame(90, $json['duracion_minutos']);
        $this->assertSame('15000.00', $json['precio']);
        $this->assertNull($json['modo_promo']);
        $this->assertSame([], $json['componentes']);
    }

    // 2a.2 — composition
    public function test_saves_valid_components_with_position_order_and_derives_duration_and_price(): void
    {
        $json = $this->ponerComponentes($this->payloadValido())->assertOk()->json();

        $this->assertSame('secuencia', $json['modo_promo']);
        $this->assertSame(105, $json['duracion_minutos']);
        $this->assertSame('22000.00', $json['precio']);
        $this->assertDatabaseHas('servicio_componentes', [
            'servicio_id' => $this->promo->id, 'componente_servicio_id' => $this->softgel->id,
            'profesional_id' => $this->ana->id, 'orden' => 1,
        ]);
        $this->assertDatabaseHas('servicio_componentes', [
            'servicio_id' => $this->promo->id, 'componente_servicio_id' => $this->semis->id,
            'profesional_id' => $this->laura->id, 'orden' => 2,
        ]);
    }

    public function test_price_override_persists_and_duration_stays_derived(): void
    {
        $json = $this->ponerComponentes($this->payloadValido(['precio' => 18000]))->assertOk()->json();

        $this->assertSame('18000.00', $json['precio']);
        $this->assertSame(105, $json['duracion_minutos']);
    }

    public function test_saving_again_replaces_the_previous_components(): void
    {
        $this->ponerComponentes($this->payloadValido())->assertOk();
        $this->ponerComponentes([
            'modo_promo' => 'secuencia',
            'componentes' => [['servicio_id' => $this->softgel->id, 'profesional_id' => $this->ana->id]],
        ])->assertOk();

        $this->assertDatabaseCount('servicio_componentes', 1);
        $this->assertSame(60, $this->promo->fresh()->duracion_minutos);
    }

    public function test_empty_components_revert_the_promo_to_legacy(): void
    {
        $this->ponerComponentes($this->payloadValido())->assertOk();

        $json = $this->ponerComponentes(['componentes' => []])->assertOk()->json();

        $this->assertNull($json['modo_promo']);
        $this->assertSame([], $json['componentes']);
        $this->assertDatabaseCount('servicio_componentes', 0);
    }

    public function test_rejects_a_professional_who_does_not_offer_the_service_naming_the_component(): void
    {
        $payload = $this->payloadValido();
        $payload['componentes'][0]['profesional_id'] = $this->laura->id; // Laura does not offer Softgel

        $resp = $this->ponerComponentes($payload)->assertStatus(422)->assertJsonValidationErrors(['componentes.0.profesional_id']);

        $this->assertStringContainsString('Softgel', $resp->json('errors')['componentes.0.profesional_id'][0]);
        $this->assertStringContainsString('Laura', $resp->json('errors')['componentes.0.profesional_id'][0]);
        $this->assertDatabaseCount('servicio_componentes', 0);
    }

    public function test_rejects_an_inactive_professional(): void
    {
        $this->laura->update(['activo' => false]);

        $this->ponerComponentes($this->payloadValido())->assertStatus(422)
            ->assertJsonValidationErrors(['componentes.1.profesional_id']);
    }

    public function test_rejects_a_promo_as_component_and_foreign_or_inactive_services(): void
    {
        $otroPromo = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Otra promo', 'duracion_minutos' => 30, 'precio' => 1, 'activo' => true, 'es_promo' => true]);
        $ajeno = Servicio::create(['user_id' => User::factory()->create()->id, 'nombre' => 'Ajeno', 'duracion_minutos' => 30, 'precio' => 1, 'activo' => true]);
        $this->semis->update(['activo' => false]);

        foreach ([$otroPromo, $ajeno, $this->semis] as $invalido) {
            $this->ponerComponentes([
                'modo_promo' => 'secuencia',
                'componentes' => [['servicio_id' => $invalido->id, 'profesional_id' => $this->ana->id]],
            ])->assertStatus(422)->assertJsonValidationErrors(['componentes.0.servicio_id']);
        }
    }

    public function test_rejects_components_on_a_servicio_that_is_not_a_promo(): void
    {
        $this->admin()->putJson("/api/servicios/{$this->servicio->id}/componentes", $this->payloadValido())
            ->assertStatus(422);

        $this->assertDatabaseCount('servicio_componentes', 0);
    }

    public function test_sequential_allows_the_same_professional_twice(): void
    {
        $this->ana->servicios()->attach($this->semis->id);

        $this->ponerComponentes([
            'modo_promo' => 'secuencia',
            'componentes' => [
                ['servicio_id' => $this->softgel->id, 'profesional_id' => $this->ana->id],
                ['servicio_id' => $this->semis->id, 'profesional_id' => $this->ana->id],
            ],
        ])->assertOk();
    }

    public function test_requires_a_valid_mode_when_there_are_components(): void
    {
        $payload = $this->payloadValido();
        unset($payload['modo_promo']);
        $this->ponerComponentes($payload)->assertStatus(422)->assertJsonValidationErrors(['modo_promo']);

        $this->ponerComponentes($this->payloadValido(['modo_promo' => 'simultaneo']))
            ->assertStatus(422)->assertJsonValidationErrors(['modo_promo']);
    }

    // 2a.3 — parallel gates
    protected function encenderParalelo(): void
    {
        $this->user->forceFill(['atiende_en_paralelo' => true])->save();
    }

    public function test_parallel_is_rejected_while_the_studio_setting_is_off(): void
    {
        $this->ponerComponentes($this->payloadValido(['modo_promo' => 'paralelo']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'paralelo_no_habilitado');

        $this->assertDatabaseCount('servicio_componentes', 0);
    }

    public function test_parallel_is_accepted_with_the_setting_on_and_derives_the_max_duration(): void
    {
        $this->encenderParalelo();

        $json = $this->ponerComponentes($this->payloadValido(['modo_promo' => 'paralelo']))->assertOk()->json();

        $this->assertSame('paralelo', $json['modo_promo']);
        $this->assertSame(60, $json['duracion_minutos']);
    }

    public function test_parallel_requires_distinct_professionals(): void
    {
        $this->encenderParalelo();
        $this->ana->servicios()->attach($this->semis->id);

        $this->ponerComponentes([
            'modo_promo' => 'paralelo',
            'componentes' => [
                ['servicio_id' => $this->softgel->id, 'profesional_id' => $this->ana->id],
                ['servicio_id' => $this->semis->id, 'profesional_id' => $this->ana->id],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['componentes.1.profesional_id']);
    }

    // 2a.4 — GET-one detail
    protected function detalle(): array
    {
        return $this->admin()->getJson("/api/servicios/{$this->promo->id}")->assertOk()->json();
    }

    public function test_show_lists_components_in_order_with_live_derived_duration_and_price(): void
    {
        $this->ponerComponentes($this->payloadValido(['precio' => 18000]))->assertOk();

        $json = $this->detalle();

        $this->assertSame([
            [
                'orden' => 1, 'servicio_id' => $this->softgel->id, 'nombre' => 'Softgel', 'duracion_minutos' => 60,
                'precio' => '13000.00', 'profesional_id' => $this->ana->id, 'profesional_nombre' => $this->ana->nombre,
            ],
            [
                'orden' => 2, 'servicio_id' => $this->semis->id, 'nombre' => 'Semis pies', 'duracion_minutos' => 45,
                'precio' => '9000.00', 'profesional_id' => $this->laura->id, 'profesional_nombre' => 'Laura',
            ],
        ], $json['componentes']);
        $this->assertSame(105, $json['duracion_derivada']);
        $this->assertEquals(22000, $json['precio_componentes']);
        $this->assertSame('18000.00', $json['precio']); // override untouched
        $this->assertSame([], $json['problemas']);
    }

    public function test_show_derives_the_max_duration_for_parallel_and_follows_component_edits_live(): void
    {
        $this->encenderParalelo();
        $this->ponerComponentes($this->payloadValido(['modo_promo' => 'paralelo']))->assertOk();
        $this->assertSame(60, $this->detalle()['duracion_derivada']);

        $this->semis->update(['duracion_minutos' => 75, 'precio' => 10000]);

        $json = $this->detalle();
        $this->assertSame(75, $json['duracion_derivada']);
        $this->assertEquals(23000, $json['precio_componentes']);
        $this->assertSame(60, $json['duracion_minutos']); // persisted value goes stale, live one does not
    }

    public function test_show_reports_an_inactive_component_professional_naming_her_and_the_service(): void
    {
        $this->ponerComponentes($this->payloadValido())->assertOk();
        $this->laura->update(['activo' => false]);

        $problemas = $this->detalle()['problemas'];

        $this->assertCount(1, $problemas);
        $this->assertSame('profesional_inactiva', $problemas[0]['codigo']);
        $this->assertSame(2, $problemas[0]['orden']);
        $this->assertSame($this->laura->id, $problemas[0]['profesional_id']);
        $this->assertSame($this->semis->id, $problemas[0]['servicio_id']);
        $this->assertStringContainsString('Laura', $problemas[0]['mensaje']);
        $this->assertStringContainsString('Semis pies', $problemas[0]['mensaje']);
    }

    public function test_show_reports_a_service_the_professional_no_longer_offers(): void
    {
        $this->ponerComponentes($this->payloadValido())->assertOk();
        $this->ana->servicios()->detach($this->softgel->id);

        $problemas = $this->detalle()['problemas'];

        $this->assertCount(1, $problemas);
        $this->assertSame('servicio_desvinculado', $problemas[0]['codigo']);
        $this->assertSame(1, $problemas[0]['orden']);
        $this->assertStringContainsString($this->ana->nombre, $problemas[0]['mensaje']);
        $this->assertStringContainsString('Softgel', $problemas[0]['mensaje']);
    }

    public function test_show_reports_one_problem_per_cause_and_component(): void
    {
        $this->ponerComponentes($this->payloadValido())->assertOk();
        $this->laura->update(['activo' => false]);
        $this->laura->servicios()->detach($this->semis->id);
        $this->ana->servicios()->detach($this->softgel->id);

        $this->assertSame(
            [[1, 'servicio_desvinculado'], [2, 'profesional_inactiva'], [2, 'servicio_desvinculado']],
            collect($this->detalle()['problemas'])->map(fn ($p) => [$p['orden'], $p['codigo']])->all(),
        );
    }

    // 2a.5 — destroy guard
    public function test_destroy_is_blocked_for_a_service_used_as_a_component_and_lists_the_promos(): void
    {
        $this->ponerComponentes($this->payloadValido())->assertOk();
        $otraPromo = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Otra promo', 'duracion_minutos' => 60, 'precio' => 1, 'activo' => true, 'es_promo' => true]);
        $this->admin()->putJson("/api/servicios/{$otraPromo->id}/componentes", [
            'modo_promo' => 'secuencia',
            'componentes' => [['servicio_id' => $this->softgel->id, 'profesional_id' => $this->ana->id]],
        ])->assertOk();

        $this->admin()->deleteJson("/api/servicios/{$this->softgel->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'servicio_en_promo')
            ->assertJsonPath('promos', [
                ['id' => $this->promo->id, 'nombre' => 'Softgel + Semis pies'],
                ['id' => $otraPromo->id, 'nombre' => 'Otra promo'],
            ])
            ->assertJsonStructure(['message']);

        $this->assertDatabaseHas('servicios', ['id' => $this->softgel->id]);
    }

    public function test_destroy_of_a_service_not_used_as_a_component_is_unchanged(): void
    {
        $this->ponerComponentes($this->payloadValido())->assertOk();
        $libre = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Libre', 'duracion_minutos' => 30, 'precio' => 1, 'activo' => true]);

        $this->admin()->deleteJson("/api/servicios/{$libre->id}")
            ->assertOk()->assertExactJson(['message' => 'Servicio eliminado correctamente.']);

        $this->assertDatabaseMissing('servicios', ['id' => $libre->id]);
    }

    public function test_destroying_the_promo_itself_still_works_and_frees_its_components(): void
    {
        $this->ponerComponentes($this->payloadValido())->assertOk();

        $this->admin()->deleteJson("/api/servicios/{$this->promo->id}")->assertOk();
        $this->admin()->deleteJson("/api/servicios/{$this->softgel->id}")->assertOk();

        $this->assertDatabaseCount('servicio_componentes', 0);
    }

    public function test_legacy_turnos_conflict_takes_precedence_over_the_promo_guard(): void
    {
        $this->admin()->putJson("/api/servicios/{$this->promo->id}/componentes", [
            'modo_promo' => 'secuencia',
            'componentes' => [['servicio_id' => $this->servicio->id, 'profesional_id' => $this->ana->id]],
        ])->assertOk();
        $this->crearTurno();

        $this->admin()->deleteJson("/api/servicios/{$this->servicio->id}")
            ->assertStatus(409)
            ->assertJsonMissingPath('code');
    }

    public function test_parallel_is_treated_as_off_with_a_single_active_professional(): void
    {
        $this->encenderParalelo();
        $this->laura->update(['activo' => false]);

        $this->ponerComponentes([
            'modo_promo' => 'paralelo',
            'componentes' => [['servicio_id' => $this->softgel->id, 'profesional_id' => $this->ana->id]],
        ])->assertStatus(422)->assertJsonPath('code', 'paralelo_no_habilitado');
    }
}

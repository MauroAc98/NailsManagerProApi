<?php

namespace Tests\Feature\Combo;

use App\Models\Profesional;
use App\Models\Servicio;
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
}

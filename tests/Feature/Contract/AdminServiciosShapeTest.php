<?php

namespace Tests\Feature\Contract;

/**
 * Characterization pins for the admin servicios CRUD (raw Servicio model).
 */
class AdminServiciosShapeTest extends AdminContractTestCase
{
    private function servicioConFotosShape(): array
    {
        return array_merge(self::SERVICIO, ['fotos' => 'array']);
    }

    public function test_index_shape(): void
    {
        $json = $this->admin()->getJson('/api/servicios')->assertOk()->json();

        $this->assertContractShape(['*' => $this->servicioConFotosShape()], $json);
    }

    public function test_show_shape(): void
    {
        $json = $this->admin()->getJson("/api/servicios/{$this->servicio->id}")->assertOk()->json();

        $this->assertContractShape($this->servicioConFotosShape(), $json);
    }

    public function test_store_shape(): void
    {
        $json = $this->admin()
            ->postJson('/api/servicios', ['nombre' => 'Nuevo', 'duracion_minutos' => 45, 'precio' => 2000])
            ->assertCreated()
            ->json();

        // A freshly created Servicio has no `activo` key yet (DB default only).
        $shape = $this->servicioConFotosShape();
        unset($shape['activo']);
        $this->assertContractShape($shape, $json);
    }

    public function test_update_shape(): void
    {
        $json = $this->admin()
            ->putJson("/api/servicios/{$this->servicio->id}", ['precio' => 1200])
            ->assertOk()
            ->json();

        $this->assertContractShape($this->servicioConFotosShape(), $json);
    }

    public function test_destroy_shape(): void
    {
        $json = $this->admin()->deleteJson("/api/servicios/{$this->servicio->id}")->assertOk()->json();

        $this->assertContractShape(['message' => 'string'], $json);
    }
}

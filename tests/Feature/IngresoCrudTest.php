<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngresoCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_un_ingreso_con_todos_los_campos(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/ingresos', [
                'fecha' => '2026-08-01',
                'monto' => 1500.50,
                'categoria' => 'venta_productos',
                'descripcion' => 'Venta de esmaltes',
            ]);

        $response->assertCreated();
        $response->assertJsonFragment([
            'categoria' => 'venta_productos',
            'descripcion' => 'Venta de esmaltes',
        ]);

        $this->assertDatabaseHas('ingresos', [
            'user_id' => $user->id,
            'categoria' => 'venta_productos',
        ]);
    }

    public function test_crea_un_ingreso_solo_con_campos_requeridos(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/ingresos', [
                'fecha' => '2026-08-01',
                'monto' => 500,
                'categoria' => 'alquiler_espacio',
            ]);

        $response->assertCreated();
        $response->assertJsonFragment([
            'categoria' => 'alquiler_espacio',
            'descripcion' => null,
        ]);
    }

    public function test_rechaza_categoria_invalida(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/ingresos', [
                'fecha' => '2026-08-01',
                'monto' => 500,
                'categoria' => 'categoria_inventada',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['categoria']);

        $this->assertDatabaseCount('ingresos', 0);
    }

    public function test_rechaza_monto_cero(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/ingresos', [
                'fecha' => '2026-08-01',
                'monto' => 0,
                'categoria' => 'venta_productos',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['monto']);

        $this->assertDatabaseCount('ingresos', 0);
    }

    public function test_lista_solo_los_ingresos_propios(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        $user->ingresos()->create(['fecha' => '2026-08-01', 'monto' => 100, 'categoria' => 'venta_productos']);
        $user->ingresos()->create(['fecha' => '2026-08-02', 'monto' => 200, 'categoria' => 'alquiler_espacio']);
        $user->ingresos()->create(['fecha' => '2026-08-03', 'monto' => 300, 'categoria' => 'otros']);

        $otroUsuario->ingresos()->create(['fecha' => '2026-08-01', 'monto' => 999, 'categoria' => 'otros']);
        $otroUsuario->ingresos()->create(['fecha' => '2026-08-02', 'monto' => 999, 'categoria' => 'otros']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/ingresos');

        $response->assertOk();
        $response->assertJsonCount(3);
    }

    public function test_lista_ingresos_filtrados_por_desde_y_hasta(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $user->ingresos()->create(['fecha' => '2026-07-15', 'monto' => 100, 'categoria' => 'venta_productos']);
        $user->ingresos()->create(['fecha' => '2026-08-01', 'monto' => 200, 'categoria' => 'alquiler_espacio']);
        $user->ingresos()->create(['fecha' => '2026-08-15', 'monto' => 300, 'categoria' => 'otros']);
        $user->ingresos()->create(['fecha' => '2026-09-01', 'monto' => 400, 'categoria' => 'otros']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/ingresos?desde=2026-08-01&hasta=2026-08-31');

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['monto' => '200.00']);
        $response->assertJsonFragment(['monto' => '300.00']);
        $response->assertJsonMissing(['monto' => '100.00']);
        $response->assertJsonMissing(['monto' => '400.00']);
    }

    public function test_lista_ingresos_filtrados_solo_por_desde(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $user->ingresos()->create(['fecha' => '2026-07-15', 'monto' => 100, 'categoria' => 'venta_productos']);
        $user->ingresos()->create(['fecha' => '2026-08-01', 'monto' => 200, 'categoria' => 'alquiler_espacio']);
        $user->ingresos()->create(['fecha' => '2026-09-01', 'monto' => 300, 'categoria' => 'otros']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/ingresos?desde=2026-08-01');

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['monto' => '200.00']);
        $response->assertJsonFragment(['monto' => '300.00']);
        $response->assertJsonMissing(['monto' => '100.00']);
    }

    public function test_lista_ingresos_filtrados_solo_por_hasta(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $user->ingresos()->create(['fecha' => '2026-07-15', 'monto' => 100, 'categoria' => 'venta_productos']);
        $user->ingresos()->create(['fecha' => '2026-08-01', 'monto' => 200, 'categoria' => 'alquiler_espacio']);
        $user->ingresos()->create(['fecha' => '2026-09-01', 'monto' => 300, 'categoria' => 'otros']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/ingresos?hasta=2026-08-01');

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['monto' => '100.00']);
        $response->assertJsonFragment(['monto' => '200.00']);
        $response->assertJsonMissing(['monto' => '300.00']);
    }

    public function test_rechaza_hasta_anterior_a_desde(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/ingresos?desde=2026-08-15&hasta=2026-08-01');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['hasta']);
    }

    public function test_rechaza_fecha_malformada_en_filtro(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/ingresos?desde=no-es-una-fecha');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['desde']);
    }

    public function test_lista_ingresos_filtrados_por_rango_de_un_solo_dia(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $user->ingresos()->create(['fecha' => '2026-08-01', 'monto' => 100, 'categoria' => 'venta_productos']);
        $user->ingresos()->create(['fecha' => '2026-08-02', 'monto' => 200, 'categoria' => 'alquiler_espacio']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/ingresos?desde=2026-08-02&hasta=2026-08-02');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['monto' => '200.00']);
    }

    public function test_lista_ingresos_sin_filtros_devuelve_todos_ordenados(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $user->ingresos()->create(['fecha' => '2026-08-01', 'monto' => 100, 'categoria' => 'venta_productos']);
        $user->ingresos()->create(['fecha' => '2026-08-03', 'monto' => 200, 'categoria' => 'alquiler_espacio']);
        $user->ingresos()->create(['fecha' => '2026-08-02', 'monto' => 300, 'categoria' => 'otros']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/ingresos');

        $response->assertOk();
        $response->assertJsonCount(3);
        $fechas = collect($response->json())->pluck('fecha')->all();
        $this->assertSame(['2026-08-03', '2026-08-02', '2026-08-01'], $fechas);
    }

    public function test_muestra_un_ingreso_ajeno_devuelve_404(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        $ingresoAjeno = $otroUsuario->ingresos()->create(['fecha' => '2026-08-01', 'monto' => 999, 'categoria' => 'otros']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/ingresos/{$ingresoAjeno->id}")
            ->assertStatus(404);
    }

    public function test_actualiza_monto_y_categoria(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $ingreso = $user->ingresos()->create([
            'fecha' => '2026-08-01',
            'monto' => 100,
            'categoria' => 'venta_productos',
            'descripcion' => 'Original',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/ingresos/{$ingreso->id}", [
                'monto' => 250.75,
                'categoria' => 'alquiler_espacio',
            ]);

        $response->assertOk();
        $response->assertJsonFragment([
            'categoria' => 'alquiler_espacio',
            'descripcion' => 'Original',
        ]);

        $this->assertDatabaseHas('ingresos', [
            'id' => $ingreso->id,
            'monto' => 250.75,
            'categoria' => 'alquiler_espacio',
        ]);
    }

    public function test_elimina_un_ingreso_sin_condiciones(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $ingreso = $user->ingresos()->create(['fecha' => '2026-08-01', 'monto' => 100, 'categoria' => 'venta_productos']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/ingresos/{$ingreso->id}")
            ->assertOk();

        $this->assertDatabaseMissing('ingresos', ['id' => $ingreso->id]);
    }

    public function test_eliminar_un_ingreso_ajeno_devuelve_404(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        $ingresoAjeno = $otroUsuario->ingresos()->create(['fecha' => '2026-08-01', 'monto' => 999, 'categoria' => 'otros']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/ingresos/{$ingresoAjeno->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('ingresos', ['id' => $ingresoAjeno->id]);
    }
}

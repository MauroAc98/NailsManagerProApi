<?php

namespace Tests\Feature;

use App\Models\Gasto;
use App\Models\Ingreso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserCategoriasTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_sin_columnas_resuelve_a_las_categorias_de_fabrica(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->assertSame(Gasto::CATEGORIAS, $user->categorias_gasto);
        $this->assertSame(Ingreso::CATEGORIAS, $user->categorias_ingreso);
    }

    public function test_me_incluye_la_lista_resuelta_para_un_usuario_de_fabrica(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('categorias_gasto', Gasto::CATEGORIAS)
            ->assertJsonPath('categorias_ingreso', Ingreso::CATEGORIAS);
    }

    public function test_update_perfil_persiste_una_lista_custom_de_gastos(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'categorias_gasto' => ['insumos', 'sueldos', 'impuestos'],
            ])
            ->assertOk();

        $response->assertJsonPath('categorias_gasto', ['insumos', 'sueldos', 'impuestos']);
        $this->assertSame(['insumos', 'sueldos', 'impuestos'], $user->fresh()->categorias_gasto);
        // Ingresos siguen resolviendo al default.
        $this->assertSame(Ingreso::CATEGORIAS, $user->fresh()->categorias_ingreso);
    }

    public function test_update_perfil_persiste_una_lista_custom_de_ingresos(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'categorias_ingreso' => ['servicios', 'productos', 'cursos'],
            ])
            ->assertOk()
            ->assertJsonPath('categorias_ingreso', ['servicios', 'productos', 'cursos']);

        $this->assertSame(['servicios', 'productos', 'cursos'], $user->fresh()->categorias_ingreso);
    }

    public function test_update_perfil_recorta_y_normaliza_los_nombres(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'categorias_gasto' => ['  insumos  ', "sueldos\tfijos", 'marketing   digital'],
            ])
            ->assertOk()
            ->assertJsonPath('categorias_gasto', ['insumos', 'sueldos fijos', 'marketing digital']);
    }

    public function test_update_perfil_rechaza_lista_vacia(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', ['categorias_gasto' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['categorias_gasto']);

        $this->assertNull($user->fresh()->getRawOriginal('categorias_gasto'));
    }

    public function test_update_perfil_rechaza_valor_no_array(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', ['categorias_gasto' => 'insumos'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['categorias_gasto']);
    }

    public function test_update_perfil_rechaza_nombre_demasiado_largo(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'categorias_gasto' => ['insumos', str_repeat('x', 41)],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['categorias_gasto.1']);
    }

    public function test_update_perfil_rechaza_duplicados(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'categorias_gasto' => ['insumos', 'insumos'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['categorias_gasto.0']);
    }

    public function test_update_perfil_rechaza_duplicados_tras_normalizar(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        // `distinct` mira el valor crudo y no ve la colisión; el colapso de
        // espacios internos la produce.
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'categorias_gasto' => ['marketing digital', 'marketing  digital'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['categorias_gasto']);
    }

    public function test_update_perfil_rechaza_string_vacio_post_trim(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/perfil', [
                'categorias_gasto' => ['insumos', '   '],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['categorias_gasto.1']);
    }

    public function test_me_devuelve_la_lista_custom_tras_guardarla(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $user->update(['categorias_gasto' => ['a', 'b']]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('categorias_gasto', ['a', 'b']);
    }
}

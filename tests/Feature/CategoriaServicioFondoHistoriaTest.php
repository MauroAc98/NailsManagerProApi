<?php

namespace Tests\Feature;

use App\Models\CategoriaServicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategoriaServicioFondoHistoriaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private CategoriaServicio $categoria;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->user = User::factory()->create(['is_exempt' => true]);
        $this->categoria = CategoriaServicio::create(['user_id' => $this->user->id, 'nombre' => 'Manicura']);
    }

    private function subir(int $id)
    {
        return $this->actingAs($this->user, 'sanctum')
            ->post("/api/categorias-servicio/{$id}/fondo-historia", [
                'imagen' => UploadedFile::fake()->create('fondo.jpg', 100, 'image/jpeg'),
            ], ['Accept' => 'application/json']);
    }

    public function test_json_expone_fondo_historia_url_null_por_defecto(): void
    {
        $this->actingAs($this->user, 'sanctum')->getJson('/api/categorias-servicio')
            ->assertOk()->assertJsonPath('0.fondo_historia_url', null)
            ->assertJsonMissingPath('0.fondo_historia_path');
    }

    public function test_sube_el_fondo_y_devuelve_la_categoria_con_url(): void
    {
        $res = $this->subir($this->categoria->id)->assertOk();

        $path = $this->categoria->fresh()->getRawOriginal('fondo_historia_path');
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('fondos_historia/', $path);
        $res->assertJsonPath('fondo_historia_url', Storage::disk('public')->url($path))
            ->assertJsonPath('id', $this->categoria->id)
            ->assertJsonMissingPath('fondo_historia_path');

        $this->actingAs($this->user, 'sanctum')->getJson("/api/categorias-servicio/{$this->categoria->id}")
            ->assertJsonPath('fondo_historia_url', Storage::disk('public')->url($path));
    }

    public function test_reemplazar_borra_el_archivo_anterior(): void
    {
        $this->subir($this->categoria->id)->assertOk();
        $anterior = $this->categoria->fresh()->getRawOriginal('fondo_historia_path');

        $this->subir($this->categoria->id)->assertOk();
        $nuevo = $this->categoria->fresh()->getRawOriginal('fondo_historia_path');

        $this->assertNotSame($anterior, $nuevo);
        Storage::disk('public')->assertMissing($anterior);
        Storage::disk('public')->assertExists($nuevo);
    }

    public function test_borra_el_fondo(): void
    {
        $this->subir($this->categoria->id)->assertOk();
        $path = $this->categoria->fresh()->getRawOriginal('fondo_historia_path');

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/categorias-servicio/{$this->categoria->id}/fondo-historia")
            ->assertOk()->assertJsonPath('fondo_historia_url', null);

        Storage::disk('public')->assertMissing($path);
        $this->assertNull($this->categoria->fresh()->getRawOriginal('fondo_historia_path'));
    }

    public function test_requiere_imagen_valida(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/categorias-servicio/{$this->categoria->id}/fondo-historia", [])
            ->assertStatus(422);
    }

    public function test_categoria_ajena_devuelve_404_en_subir_y_borrar(): void
    {
        $otro = User::factory()->create(['is_exempt' => true]);
        $ajena = CategoriaServicio::create(['user_id' => $otro->id, 'nombre' => 'Ajena']);

        $this->subir($ajena->id)->assertStatus(404);
        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/categorias-servicio/{$ajena->id}/fondo-historia")->assertStatus(404);
    }
}

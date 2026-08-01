<?php

namespace Tests\Feature;

use App\Models\HistoriaPrecioFoto;
use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfesionalHistoriaPreciosTest extends TestCase
{
    use RefreshDatabase;

    private function fakeImagen(string $nombre = 'foto.jpg'): UploadedFile
    {
        // No usamos UploadedFile::fake()->image() porque requiere la
        // extensión GD (no disponible en este entorno de test); create()
        // con mimeType explícito alcanza para pasar la regla 'image'.
        return UploadedFile::fake()->create($nombre, 100, 'image/jpeg');
    }

    public function test_patchea_layout_y_estilo_via_el_endpoint_de_update_existente(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$profesional->id}", [
                'historia_precios_layout_id' => 'grid4',
                'historia_precios_estilo_id' => 'modern',
            ])
            ->assertOk();

        $response->assertJsonPath('historia_precios_layout_id', 'grid4');
        $response->assertJsonPath('historia_precios_estilo_id', 'modern');

        $this->assertSame('grid4', $profesional->fresh()->historia_precios_layout_id);
        $this->assertSame('modern', $profesional->fresh()->historia_precios_estilo_id);
    }

    public function test_rechaza_un_valor_de_layout_que_no_esta_en_el_enum(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$profesional->id}", [
                'historia_precios_layout_id' => 'no-existe',
            ])
            ->assertStatus(422);
    }

    public function test_permite_volver_a_null_los_campos_de_historia_precios(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create([
            'user_id' => $user->id,
            'nombre' => 'Jefa',
            'activo' => true,
            'historia_precios_layout_id' => 'single',
            'historia_precios_estilo_id' => 'bold',
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$profesional->id}", [
                'historia_precios_layout_id' => null,
                'historia_precios_estilo_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('historia_precios_layout_id', null)
            ->assertJsonPath('historia_precios_estilo_id', null);
    }

    public function test_sube_una_foto_y_devuelve_el_profesional_completo(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('lista-precios.jpg'),
            ])
            ->assertOk();

        // Devuelve el Profesional COMPLETO (mismo shape que index/show), no
        // solo la foto — el reducer del frontend depende de esto.
        $response->assertJsonPath('id', $profesional->id);
        $response->assertJsonPath('nombre', 'Jefa');
        $response->assertJsonCount(1, 'historia_precios_fotos');
        $response->assertJsonStructure([
            'historia_precios_fotos' => [['id', 'url', 'orden']],
        ]);

        $this->assertSame(1, HistoriaPrecioFoto::where('profesional_id', $profesional->id)->count());

        $path = HistoriaPrecioFoto::first()->getRawOriginal('path');
        Storage::disk('public')->assertExists($path);
    }

    public function test_las_fotos_subidas_quedan_ordenadas_por_orden_de_llegada(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('a.jpg'),
            ])->assertOk();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('b.jpg'),
            ])->assertOk();

        $fotos = $response->json('historia_precios_fotos');
        $this->assertCount(2, $fotos);
        $this->assertSame(0, $fotos[0]['orden']);
        $this->assertSame(1, $fotos[1]['orden']);
    }

    public function test_rechaza_subir_una_quinta_foto(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                    'imagen' => $this->fakeImagen("foto-{$i}.jpg"),
                ])->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('foto-5.jpg'),
            ])
            ->assertStatus(422);

        $this->assertSame(4, HistoriaPrecioFoto::where('profesional_id', $profesional->id)->count());
    }

    public function test_borra_una_foto_fila_y_archivo_y_devuelve_el_profesional_completo(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $subida = $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('a.jpg'),
            ])->assertOk();

        $fotoId = $subida->json('historia_precios_fotos.0.id');
        $path = HistoriaPrecioFoto::findOrFail($fotoId)->getRawOriginal('path');

        Storage::disk('public')->assertExists($path);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/profesionales/{$profesional->id}/historia-precios-fotos/{$fotoId}")
            ->assertOk();

        $response->assertJsonPath('id', $profesional->id);
        $response->assertJsonCount(0, 'historia_precios_fotos');

        $this->assertDatabaseMissing('historia_precios_fotos', ['id' => $fotoId]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_resubir_despues_de_borrar_no_repite_el_orden_de_una_foto_existente(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        // 4 fotos: orden 0,1,2,3
        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                    'imagen' => $this->fakeImagen("foto-{$i}.jpg"),
                ])->assertOk();
        }

        // Borra la de orden 1 (flujo de "reemplazar" = delete + re-upload)
        $fotoOrden1 = HistoriaPrecioFoto::where('profesional_id', $profesional->id)
            ->where('orden', 1)->firstOrFail();
        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/profesionales/{$profesional->id}/historia-precios-fotos/{$fotoOrden1->id}")
            ->assertOk();

        // Sube una nueva — antes del fix, esto repetía 'orden' = 3 (el
        // conteo post-borrado) en vez de continuar desde el máximo real.
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('nueva.jpg'),
            ])->assertOk();

        $ordenes = collect($response->json('historia_precios_fotos'))->pluck('orden')->all();
        $this->assertSame([0, 2, 3, 4], $ordenes);
        $this->assertSame(4, count(array_unique($ordenes)));
    }

    public function test_no_permite_borrar_una_foto_de_otro_usuario(): void
    {
        Storage::fake('public');

        $dueno = User::factory()->create(['is_exempt' => true]);
        $intruso = User::factory()->create(['is_exempt' => true]);

        $profesional = Profesional::create(['user_id' => $dueno->id, 'nombre' => 'Jefa', 'activo' => true]);

        $subida = $this->actingAs($dueno, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('a.jpg'),
            ])->assertOk();

        $fotoId = $subida->json('historia_precios_fotos.0.id');

        $this->actingAs($intruso, 'sanctum')
            ->deleteJson("/api/profesionales/{$profesional->id}/historia-precios-fotos/{$fotoId}")
            ->assertStatus(404);

        $this->assertDatabaseHas('historia_precios_fotos', ['id' => $fotoId]);
    }
}

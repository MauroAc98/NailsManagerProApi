<?php

namespace Tests\Feature;

use App\Models\Servicio;
use App\Models\ServicioFoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServicioFotosTest extends TestCase
{
    use RefreshDatabase;

    private function fakeImagen(string $nombre = 'foto.jpg'): UploadedFile
    {
        // No usamos UploadedFile::fake()->image() porque requiere la
        // extensión GD (no disponible en este entorno de test); create()
        // con mimeType explícito alcanza para pasar la regla 'image'.
        return UploadedFile::fake()->create($nombre, 100, 'image/jpeg');
    }

    private function crearServicio(User $user): Servicio
    {
        return Servicio::create([
            'user_id' => $user->id,
            'nombre' => 'Manicura',
            'duracion_minutos' => 30,
            'activo' => true,
        ]);
    }

    public function test_sube_una_foto_y_devuelve_el_servicio_completo(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $servicio = $this->crearServicio($user);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/servicios/{$servicio->id}/fotos", [
                'imagen' => $this->fakeImagen(),
            ])
            ->assertOk();

        $response->assertJsonPath('id', $servicio->id);
        $response->assertJsonCount(1, 'fotos');
        $response->assertJsonStructure([
            'fotos' => [['id', 'url', 'orden']],
        ]);

        $this->assertSame(1, ServicioFoto::where('servicio_id', $servicio->id)->count());

        $path = ServicioFoto::first()->getRawOriginal('path');
        Storage::disk('public')->assertExists($path);
    }

    public function test_las_fotos_quedan_con_orden_desde_el_maximo_existente(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $servicio = $this->crearServicio($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/servicios/{$servicio->id}/fotos", ['imagen' => $this->fakeImagen('a.jpg')])
            ->assertOk();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/servicios/{$servicio->id}/fotos", ['imagen' => $this->fakeImagen('b.jpg')])
            ->assertOk();

        $fotos = $response->json('fotos');
        $this->assertCount(2, $fotos);
        $this->assertSame(0, $fotos[0]['orden']);
        $this->assertSame(1, $fotos[1]['orden']);
    }

    public function test_rechaza_subir_una_decimotercera_foto(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $servicio = $this->crearServicio($user);

        for ($i = 0; $i < 12; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/servicios/{$servicio->id}/fotos", [
                    'imagen' => $this->fakeImagen("foto-{$i}.jpg"),
                ])->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/servicios/{$servicio->id}/fotos", [
                'imagen' => $this->fakeImagen('foto-13.jpg'),
            ])
            ->assertStatus(422);

        $this->assertSame(12, ServicioFoto::where('servicio_id', $servicio->id)->count());
    }

    public function test_rechaza_subir_un_svg_como_foto_de_servicio(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $servicio = $this->crearServicio($user);

        $svg = UploadedFile::fake()->create('malicioso.svg', 10, 'image/svg+xml');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/servicios/{$servicio->id}/fotos", ['imagen' => $svg])
            ->assertStatus(422);

        $this->assertSame(0, ServicioFoto::where('servicio_id', $servicio->id)->count());
    }

    public function test_borra_una_foto_fila_y_archivo_y_devuelve_el_servicio_completo(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $servicio = $this->crearServicio($user);

        $subida = $this->actingAs($user, 'sanctum')
            ->postJson("/api/servicios/{$servicio->id}/fotos", ['imagen' => $this->fakeImagen()])
            ->assertOk();

        $fotoId = $subida->json('fotos.0.id');
        $path = ServicioFoto::findOrFail($fotoId)->getRawOriginal('path');
        Storage::disk('public')->assertExists($path);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/servicios/{$servicio->id}/fotos/{$fotoId}")
            ->assertOk();

        $response->assertJsonPath('id', $servicio->id);
        $response->assertJsonCount(0, 'fotos');

        $this->assertDatabaseMissing('servicio_fotos', ['id' => $fotoId]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_no_permite_borrar_una_foto_de_un_servicio_de_otro_usuario(): void
    {
        Storage::fake('public');

        $dueno = User::factory()->create(['is_exempt' => true]);
        $intruso = User::factory()->create(['is_exempt' => true]);

        $servicio = $this->crearServicio($dueno);

        $subida = $this->actingAs($dueno, 'sanctum')
            ->postJson("/api/servicios/{$servicio->id}/fotos", ['imagen' => $this->fakeImagen()])
            ->assertOk();

        $fotoId = $subida->json('fotos.0.id');

        $this->actingAs($intruso, 'sanctum')
            ->deleteJson("/api/servicios/{$servicio->id}/fotos/{$fotoId}")
            ->assertStatus(404);

        $this->assertDatabaseHas('servicio_fotos', ['id' => $fotoId]);
    }

    public function test_reordena_las_fotos_via_el_endpoint_de_reordenar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $servicio = $this->crearServicio($user);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/servicios/{$servicio->id}/fotos", [
                    'imagen' => $this->fakeImagen("foto-{$i}.jpg"),
                ])->assertOk();
        }

        $idsOriginal = ServicioFoto::where('servicio_id', $servicio->id)->orderBy('orden')->pluck('id')->all();
        $idsInvertidos = array_reverse($idsOriginal);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/servicios/{$servicio->id}/fotos/reordenar", [
                'ids' => $idsInvertidos,
            ])
            ->assertOk();

        $fotos = collect($response->json('fotos'))->sortBy('orden')->values();
        $this->assertSame($idsInvertidos, $fotos->pluck('id')->all());
        $this->assertSame([0, 1, 2], $fotos->pluck('orden')->all());
    }

    public function test_rechaza_reordenar_con_una_foto_de_otro_servicio(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $servicioA = $this->crearServicio($user);
        $servicioB = Servicio::create([
            'user_id' => $user->id,
            'nombre' => 'Pedicura',
            'duracion_minutos' => 40,
            'activo' => true,
        ]);

        $subidaA = $this->actingAs($user, 'sanctum')
            ->postJson("/api/servicios/{$servicioA->id}/fotos", ['imagen' => $this->fakeImagen('a.jpg')])
            ->assertOk();
        $fotoIdA = $subidaA->json('fotos.0.id');

        $subidaB = $this->actingAs($user, 'sanctum')
            ->postJson("/api/servicios/{$servicioB->id}/fotos", ['imagen' => $this->fakeImagen('b.jpg')])
            ->assertOk();
        $fotoIdB = $subidaB->json('fotos.0.id');

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/servicios/{$servicioA->id}/fotos/reordenar", [
                'ids' => [$fotoIdA, $fotoIdB],
            ])
            ->assertStatus(422);
    }

    public function test_rechaza_reordenar_con_una_foto_de_un_servicio_de_otro_usuario(): void
    {
        Storage::fake('public');

        $dueno = User::factory()->create(['is_exempt' => true]);
        $intruso = User::factory()->create(['is_exempt' => true]);

        $servicioDueno = $this->crearServicio($dueno);
        $servicioIntruso = $this->crearServicio($intruso);

        $subidaDueno = $this->actingAs($dueno, 'sanctum')
            ->postJson("/api/servicios/{$servicioDueno->id}/fotos", ['imagen' => $this->fakeImagen('a.jpg')])
            ->assertOk();
        $fotoIdDueno = $subidaDueno->json('fotos.0.id');

        $subidaIntruso = $this->actingAs($intruso, 'sanctum')
            ->postJson("/api/servicios/{$servicioIntruso->id}/fotos", ['imagen' => $this->fakeImagen('b.jpg')])
            ->assertOk();
        $fotoIdIntruso = $subidaIntruso->json('fotos.0.id');

        $this->actingAs($dueno, 'sanctum')
            ->patchJson("/api/servicios/{$servicioDueno->id}/fotos/reordenar", [
                'ids' => [$fotoIdDueno, $fotoIdIntruso],
            ])
            ->assertStatus(422);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfesionalAvatarTest extends TestCase
{
    use RefreshDatabase;

    private function fakeImagen(string $nombre = 'avatar.jpg'): UploadedFile
    {
        // No usamos UploadedFile::fake()->image() porque requiere la
        // extensión GD (no disponible en este entorno de test); create()
        // con mimeType explícito alcanza para pasar la regla 'image'.
        return UploadedFile::fake()->create($nombre, 100, 'image/jpeg');
    }

    public function test_sube_un_avatar_y_devuelve_el_profesional_con_avatar_url(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/avatar", [
                'imagen' => $this->fakeImagen(),
            ])
            ->assertOk();

        $response->assertJsonPath('id', $profesional->id);
        $this->assertNotNull($response->json('avatar_url'));

        $path = $profesional->fresh()->getRawOriginal('avatar_path');
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_rechaza_subir_un_svg_como_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $svg = UploadedFile::fake()->create('malicioso.svg', 10, 'image/svg+xml');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/avatar", [
                'imagen' => $svg,
            ])
            ->assertStatus(422);

        $this->assertNull($profesional->fresh()->getRawOriginal('avatar_path'));
    }

    public function test_subir_un_segundo_avatar_borra_el_anterior_recien_despues_de_guardar_el_nuevo(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/avatar", [
                'imagen' => $this->fakeImagen('primero.jpg'),
            ])->assertOk();

        $pathAnterior = $profesional->fresh()->getRawOriginal('avatar_path');
        Storage::disk('public')->assertExists($pathAnterior);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/avatar", [
                'imagen' => $this->fakeImagen('segundo.jpg'),
            ])->assertOk();

        $pathNuevo = $profesional->fresh()->getRawOriginal('avatar_path');

        $this->assertNotSame($pathAnterior, $pathNuevo);
        Storage::disk('public')->assertExists($pathNuevo);
        Storage::disk('public')->assertMissing($pathAnterior);
    }

    public function test_borra_el_avatar_archivo_y_columna(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/avatar", [
                'imagen' => $this->fakeImagen(),
            ])->assertOk();

        $path = $profesional->fresh()->getRawOriginal('avatar_path');

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/profesionales/{$profesional->id}/avatar")
            ->assertOk();

        $response->assertJsonPath('id', $profesional->id);
        $this->assertNull($response->json('avatar_url'));

        $this->assertNull($profesional->fresh()->getRawOriginal('avatar_path'));
        Storage::disk('public')->assertMissing($path);
    }
}

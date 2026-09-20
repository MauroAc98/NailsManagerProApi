<?php

namespace Tests\Unit;

use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfesionalAvatarUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_avatar_url_es_null_cuando_avatar_path_es_null(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create([
            'user_id' => $user->id,
            'nombre' => 'Jefa',
            'activo' => true,
        ]);

        $this->assertNull($profesional->avatar_url);
    }

    public function test_avatar_url_devuelve_la_url_del_disco_public_cuando_hay_path(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create([
            'user_id' => $user->id,
            'nombre' => 'Jefa',
            'activo' => true,
            'avatar_path' => 'avatars/foto.jpg',
        ]);

        $this->assertSame(
            Storage::disk('public')->url('avatars/foto.jpg'),
            $profesional->avatar_url
        );
    }
}

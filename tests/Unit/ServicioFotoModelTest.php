<?php

namespace Tests\Unit;

use App\Models\Servicio;
use App\Models\ServicioFoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServicioFotoModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_url_accessor_devuelve_la_url_del_disco_public(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $servicio = Servicio::create([
            'user_id' => $user->id,
            'nombre' => 'Manicura',
            'duracion_minutos' => 30,
            'activo' => true,
        ]);

        $foto = ServicioFoto::create([
            'servicio_id' => $servicio->id,
            'path' => 'servicio_fotos/foto.jpg',
            'orden' => 0,
        ]);

        $this->assertSame(
            Storage::disk('public')->url('servicio_fotos/foto.jpg'),
            $foto->url
        );
    }

    public function test_path_servicio_id_y_timestamps_estan_ocultos(): void
    {
        $foto = new ServicioFoto();

        $array = $foto->toArray();

        $this->assertArrayNotHasKey('path', $array);
        $this->assertArrayNotHasKey('servicio_id', $array);
        $this->assertArrayNotHasKey('created_at', $array);
        $this->assertArrayNotHasKey('updated_at', $array);
    }
}

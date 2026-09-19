<?php

namespace Tests\Unit;

use App\Models\Servicio;
use App\Models\ServicioFoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicioFotosRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fotos_devuelve_las_fotos_del_servicio_ordenadas_por_orden(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $servicio = Servicio::create([
            'user_id' => $user->id,
            'nombre' => 'Manicura',
            'duracion_minutos' => 30,
            'activo' => true,
        ]);

        ServicioFoto::create(['servicio_id' => $servicio->id, 'path' => 'b.jpg', 'orden' => 1]);
        ServicioFoto::create(['servicio_id' => $servicio->id, 'path' => 'a.jpg', 'orden' => 0]);

        $ordenados = $servicio->fotos()->pluck('orden')->all();

        $this->assertSame([0, 1], $ordenados);
    }
}

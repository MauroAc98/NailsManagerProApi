<?php

namespace Tests\Feature;

use App\Models\BloqueoAgenda;
use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BloqueoAgendaControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── GET /api/bloqueos ─────────────────────────────────────────

    public function test_index_devuelve_solo_los_bloqueos_de_la_cuenta_ordenados_por_fecha(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        BloqueoAgenda::create(['user_id' => $otroUsuario->id, 'fecha' => '2099-01-01']);
        $segundo = BloqueoAgenda::create(['user_id' => $user->id, 'fecha' => '2099-06-15']);
        $primero = BloqueoAgenda::create(['user_id' => $user->id, 'fecha' => '2099-03-10']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/bloqueos')->assertOk();

        $response->assertJsonCount(2);
        $this->assertSame([$primero->id, $segundo->id], collect($response->json())->pluck('id')->all());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckSubscriptionSuspendidaTest extends TestCase
{
    use RefreshDatabase;

    public function test_suscripcion_suspendida_corta_el_acceso_con_403(): void
    {
        $user = User::factory()->create(['is_exempt' => false]);
        Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(10),
            'status' => 'SUSPENDIDO',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/gastos')
            ->assertStatus(403)
            ->assertJson([
                'error' => 'Suscripción suspendida',
                'code'  => 'SUBSCRIPTION_SUSPENDED',
            ]);
    }

    public function test_suscripcion_activa_deja_pasar(): void
    {
        $user = User::factory()->create(['is_exempt' => false]);
        Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(10),
            'status' => 'ACTIVO',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/gastos')
            ->assertOk();
    }
}

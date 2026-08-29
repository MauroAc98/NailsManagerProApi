<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporta_suspendido_al_dueno_aunque_le_queden_dias(): void
    {
        $user = User::factory()->create(['is_exempt' => false]);
        Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(10),
            'status' => 'SUSPENDIDO',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/subscription-status')
            ->assertOk()
            ->assertJson(['status' => 'SUSPENDIDO', 'is_exempt' => false]);
    }

    public function test_reporta_activo_cuando_no_esta_suspendida_y_le_quedan_dias(): void
    {
        $user = User::factory()->create(['is_exempt' => false]);
        Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(10),
            'status' => 'ACTIVO',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/subscription-status')
            ->assertOk()
            ->assertJson(['status' => 'ACTIVO']);
    }
}

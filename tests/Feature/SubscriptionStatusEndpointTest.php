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
            ->assertJson([
                'status' => 'SUSPENDIDO',
                'is_exempt' => false,
                'code' => 'SUBSCRIPTION_SUSPENDED',
            ]);
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
            ->assertJson(['status' => 'ACTIVO', 'code' => null]);
    }

    public function test_reporta_code_null_para_cuenta_exenta(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/subscription-status')
            ->assertOk()
            ->assertJson([
                'status' => 'ACTIVO',
                'is_exempt' => true,
                'code' => null,
            ]);
    }

    public function test_reporta_no_subscription_cuando_no_hay_fila(): void
    {
        $user = User::factory()->create(['is_exempt' => false]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/subscription-status')
            ->assertOk()
            ->assertJson([
                'status' => 'VENCIDO',
                'is_exempt' => false,
                'days_left' => 0,
                'code' => 'NO_SUBSCRIPTION',
            ]);
    }

    public function test_reporta_subscription_expired_cuando_ends_at_pasado(): void
    {
        $user = User::factory()->create(['is_exempt' => false]);
        Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->subDay(),
            'status' => 'ACTIVO',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/subscription-status')
            ->assertOk()
            ->assertJson([
                'status' => 'VENCIDO',
                'code' => 'SUBSCRIPTION_EXPIRED',
            ]);
    }
}

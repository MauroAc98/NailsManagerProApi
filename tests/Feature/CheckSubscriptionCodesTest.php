<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckSubscriptionCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_cuenta_exenta_pasa_sin_suscripcion(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/gastos')
            ->assertOk();
    }

    public function test_sin_suscripcion_devuelve_codigo_no_subscription(): void
    {
        $user = User::factory()->create(['is_exempt' => false]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/gastos')
            ->assertStatus(403)
            ->assertJson([
                'error' => 'Suscripción vencida',
                'code'  => 'NO_SUBSCRIPTION',
            ]);
    }

    public function test_suspendida_con_ends_at_futuro_devuelve_codigo_suspended_no_expired(): void
    {
        $user = User::factory()->create(['is_exempt' => false]);
        Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(10),
            'status'  => 'SUSPENDIDO',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/gastos')
            ->assertStatus(403)
            ->assertJson([
                'error' => 'Suscripción suspendida',
                'code'  => 'SUBSCRIPTION_SUSPENDED',
            ]);
    }

    public function test_vencida_devuelve_codigo_subscription_expired(): void
    {
        $user = User::factory()->create(['is_exempt' => false]);
        Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->subDay(),
            'status'  => 'ACTIVO',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/gastos')
            ->assertStatus(403)
            ->assertJson([
                'error' => 'Suscripción vencida',
                'code'  => 'SUBSCRIPTION_EXPIRED',
            ]);
    }

    public function test_suscripcion_activa_pasa(): void
    {
        $user = User::factory()->create(['is_exempt' => false]);
        Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(10),
            'status'  => 'ACTIVO',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/gastos')
            ->assertOk();
    }
}

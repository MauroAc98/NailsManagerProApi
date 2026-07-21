<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRenewSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.admin_secret' => 'secreto-de-test']);
    }

    public function test_renovacion_temprana_preserva_dias_restantes(): void
    {
        $user = User::factory()->create();

        $subscription = $user->subscription()->create([
            'ends_at' => now()->addDays(10),
            'status' => 'VENCIDO',
        ]);

        $this->withHeader('X-Admin-Secret', 'secreto-de-test')
            ->postJson("/api/admin/subscriptions/{$user->id}/renew")
            ->assertOk();

        $expected = now()->addDays(40);

        $this->assertEqualsWithDelta(
            $expected->timestamp,
            $subscription->fresh()->ends_at->timestamp,
            5
        );
    }

    public function test_renovacion_de_suscripcion_vencida_reinicia_desde_ahora(): void
    {
        $user = User::factory()->create();

        $subscription = $user->subscription()->create([
            'ends_at' => now()->subDays(5),
            'status' => 'VENCIDO',
        ]);

        $this->withHeader('X-Admin-Secret', 'secreto-de-test')
            ->postJson("/api/admin/subscriptions/{$user->id}/renew")
            ->assertOk();

        $expected = now()->addDays(30);
        $staleExpected = now()->subDays(5)->addDays(30);

        $freshEndsAt = $subscription->fresh()->ends_at;

        $this->assertEqualsWithDelta($expected->timestamp, $freshEndsAt->timestamp, 5);
        $this->assertGreaterThan(4, abs($staleExpected->timestamp - $freshEndsAt->timestamp));
    }

    public function test_renovacion_en_el_instante_exacto_de_vencimiento(): void
    {
        $user = User::factory()->create();

        $ahora = now();
        $subscription = $user->subscription()->create([
            'ends_at' => $ahora,
            'status' => 'VENCIDO',
        ]);

        $this->withHeader('X-Admin-Secret', 'secreto-de-test')
            ->postJson("/api/admin/subscriptions/{$user->id}/renew")
            ->assertOk();

        $expected = $ahora->copy()->addDays(30);

        $this->assertEqualsWithDelta(
            $expected->timestamp,
            $subscription->fresh()->ends_at->timestamp,
            5
        );
    }

    public function test_renovacion_activa_el_status(): void
    {
        $user = User::factory()->create();

        $subscription = $user->subscription()->create([
            'ends_at' => now()->subDays(5),
            'status' => 'VENCIDO',
        ]);

        $this->withHeader('X-Admin-Secret', 'secreto-de-test')
            ->postJson("/api/admin/subscriptions/{$user->id}/renew")
            ->assertOk();

        $this->assertEquals('ACTIVO', $subscription->fresh()->status);
    }
}

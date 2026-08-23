<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRenewSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'name' => 'Superadmin',
            'email' => 'admin@turnetto.app',
            'password' => 'password-de-test',
        ]);
    }

    public function test_renovacion_temprana_preserva_dias_restantes(): void
    {
        $user = User::factory()->create();

        $subscription = $user->subscription()->create([
            'ends_at' => now()->addDays(10),
            'status' => 'VENCIDO',
        ]);

        $this->actingAs($this->admin, 'admin')
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

        $this->actingAs($this->admin, 'admin')
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

        $this->actingAs($this->admin, 'admin')
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

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/renew")
            ->assertOk();

        $this->assertEquals('ACTIVO', $subscription->fresh()->status);
    }

    public function test_bloquea_renovacion_duplicada_dentro_de_las_24hs(): void
    {
        $user = User::factory()->create();

        $subscription = $user->subscription()->create([
            'ends_at' => now()->addDays(10),
            'status' => 'ACTIVO',
            'renewed_at' => now()->subHours(2),
        ]);

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/renew")
            ->assertStatus(409)
            ->assertJsonStructure(['error', 'renewed_at', 'ends_at', 'hint']);

        $this->assertEqualsWithDelta(
            now()->addDays(10)->timestamp,
            $subscription->fresh()->ends_at->timestamp,
            5
        );
    }

    public function test_permite_forzar_renovacion_duplicada_con_query_param(): void
    {
        $user = User::factory()->create();

        $subscription = $user->subscription()->create([
            'ends_at' => now()->addDays(10),
            'status' => 'ACTIVO',
            'renewed_at' => now()->subHours(2),
        ]);

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/renew?force=true")
            ->assertOk();

        $this->assertEqualsWithDelta(
            now()->addDays(40)->timestamp,
            $subscription->fresh()->ends_at->timestamp,
            5
        );
    }

    public function test_permite_renovar_de_nuevo_pasadas_las_24hs(): void
    {
        $user = User::factory()->create();

        $subscription = $user->subscription()->create([
            'ends_at' => now()->addDays(10),
            'status' => 'ACTIVO',
            'renewed_at' => now()->subHours(25),
        ]);

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/renew")
            ->assertOk();

        $this->assertEqualsWithDelta(
            now()->addDays(40)->timestamp,
            $subscription->fresh()->ends_at->timestamp,
            5
        );
    }
}

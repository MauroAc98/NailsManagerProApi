<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

        $expected = now()->addDays(10)->addMonthsNoOverflow();

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

        $expected = now()->addMonthsNoOverflow();
        $staleExpected = now()->subDays(5)->addMonthsNoOverflow();

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

        $expected = $ahora->copy()->addMonthsNoOverflow();

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
            now()->addDays(10)->addMonthsNoOverflow()->timestamp,
            $subscription->fresh()->ends_at->timestamp,
            5
        );
    }

    public function test_rechaza_renovar_una_suscripcion_suspendida(): void
    {
        $user = User::factory()->create();

        $subscription = $user->subscription()->create([
            'ends_at' => now()->addDays(10),
            'status' => 'SUSPENDIDO',
        ]);
        $endsAtOriginal = $subscription->ends_at->timestamp;

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/renew")
            ->assertStatus(409);

        $fresh = $subscription->fresh();
        $this->assertSame('SUSPENDIDO', $fresh->status);
        $this->assertSame($endsAtOriginal, $fresh->ends_at->timestamp);

        $this->assertDatabaseMissing('admin_audit_logs', [
            'action' => 'suscripcion.renovada',
            'target_user_id' => $user->id,
        ]);
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
            now()->addDays(10)->addMonthsNoOverflow()->timestamp,
            $subscription->fresh()->ends_at->timestamp,
            5
        );
    }

    public function test_renovacion_preserva_el_dia_de_vencimiento_a_traves_de_fin_de_mes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));

        $user = User::factory()->create();

        $subscription = $user->subscription()->create([
            'ends_at' => Carbon::parse('2026-01-31 23:59:59'),
            'status' => 'ACTIVO',
        ]);

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/renew")
            ->assertOk();

        $this->assertSame('2026-02-28 23:59:59', $subscription->fresh()->ends_at->toDateTimeString());
    }

    public function test_renovaciones_sucesivas_no_arrastran_el_dia_de_vencimiento(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-02 10:00:00'));

        $user = User::factory()->create();

        $subscription = $user->subscription()->create([
            'ends_at' => Carbon::parse('2026-01-10 23:59:59'),
            'status' => 'ACTIVO',
        ]);

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/renew")
            ->assertOk();

        $this->assertSame(10, $subscription->fresh()->ends_at->day);

        Carbon::setTestNow(Carbon::parse('2026-02-05 10:00:00'));

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/renew")
            ->assertOk();

        $this->assertSame(10, $subscription->fresh()->ends_at->day);
    }
}

<?php

namespace Tests\Unit;

use App\Actions\BackfillSubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillSubscriptionStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_pone_activo_cuando_ends_at_esta_en_el_futuro(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(5),
            'status' => 'VENCIDO',
        ]);

        (new BackfillSubscriptionStatus())->handle();

        $this->assertSame('ACTIVO', $subscription->fresh()->status);
    }

    public function test_pone_vencido_cuando_ends_at_ya_paso(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->subDay(),
            'status' => 'ACTIVO',
        ]);

        (new BackfillSubscriptionStatus())->handle();

        $this->assertSame('VENCIDO', $subscription->fresh()->status);
    }

    public function test_nunca_toca_una_fila_suspendida(): void
    {
        $user = User::factory()->create();
        $futura = Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(5),
            'status' => 'SUSPENDIDO',
        ]);
        $otroUser = User::factory()->create();
        $pasada = Subscription::create([
            'user_id' => $otroUser->id,
            'ends_at' => now()->subDays(5),
            'status' => 'SUSPENDIDO',
        ]);

        (new BackfillSubscriptionStatus())->handle();

        $this->assertSame('SUSPENDIDO', $futura->fresh()->status);
        $this->assertSame('SUSPENDIDO', $pasada->fresh()->status);
    }

    public function test_devuelve_la_cantidad_de_filas_reconciliadas(): void
    {
        $u1 = User::factory()->create();
        Subscription::create(['user_id' => $u1->id, 'ends_at' => now()->addDays(5), 'status' => 'VENCIDO']);
        $u2 = User::factory()->create();
        Subscription::create(['user_id' => $u2->id, 'ends_at' => now()->subDays(5), 'status' => 'ACTIVO']);
        $u3 = User::factory()->create();
        Subscription::create(['user_id' => $u3->id, 'ends_at' => now()->addDays(5), 'status' => 'ACTIVO']);

        $reconciliadas = (new BackfillSubscriptionStatus())->handle();

        $this->assertSame(2, $reconciliadas);
    }
}

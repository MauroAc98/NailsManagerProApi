<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarcarSuscripcionesVencidasTest extends TestCase
{
    use RefreshDatabase;

    public function test_marca_vencida_una_suscripcion_con_ends_at_pasado_y_status_activo(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->subDay(),
            'status' => 'ACTIVO',
        ]);

        $this->artisan('suscripciones:marcar-vencidas')->assertExitCode(0);

        $this->assertSame('VENCIDO', $subscription->fresh()->status);
    }

    public function test_no_toca_una_suscripcion_vigente(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(5),
            'status' => 'ACTIVO',
        ]);

        $this->artisan('suscripciones:marcar-vencidas')->assertExitCode(0);

        $this->assertSame('ACTIVO', $subscription->fresh()->status);
    }

    public function test_no_falla_si_no_hay_ninguna_vencida(): void
    {
        $this->artisan('suscripciones:marcar-vencidas')->assertExitCode(0);
    }

    public function test_es_idempotente_no_rompe_si_ya_estaba_vencida(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->subDays(10),
            'status' => 'VENCIDO',
        ]);

        $this->artisan('suscripciones:marcar-vencidas')->assertExitCode(0);

        $this->assertSame('VENCIDO', $subscription->fresh()->status);
    }
}

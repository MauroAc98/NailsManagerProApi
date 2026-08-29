<?php

namespace Tests\Unit;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSuscripcionVencidaTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspendida_con_ends_at_futuro_cuenta_como_vencida(): void
    {
        $user = User::factory()->create(['is_exempt' => false]);
        Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(10),
            'status' => 'SUSPENDIDO',
        ]);

        $this->assertTrue($user->suscripcionVencida());
    }

    public function test_cuenta_exenta_con_suscripcion_suspendida_no_cuenta_como_vencida(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(10),
            'status' => 'SUSPENDIDO',
        ]);

        $this->assertFalse($user->suscripcionVencida());
    }

    public function test_activa_con_ends_at_futuro_no_cuenta_como_vencida(): void
    {
        $user = User::factory()->create(['is_exempt' => false]);
        Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(10),
            'status' => 'ACTIVO',
        ]);

        $this->assertFalse($user->suscripcionVencida());
    }

    public function test_sin_suscripcion_cuenta_como_vencida(): void
    {
        $user = User::factory()->create(['is_exempt' => false]);

        $this->assertTrue($user->suscripcionVencida());
    }

    public function test_ends_at_pasado_cuenta_como_vencida(): void
    {
        $user = User::factory()->create(['is_exempt' => false]);
        Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->subDay(),
            'status' => 'ACTIVO',
        ]);

        $this->assertTrue($user->suscripcionVencida());
    }
}

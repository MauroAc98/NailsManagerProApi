<?php

namespace Tests\Feature\Pro;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same matrix as CheckSubscription (the tenant middleware), asserted on the
 * extracted User method that both the tenant gate and `pro.vigente` use.
 */
class UserCodigoDeBloqueoPorSuscripcionTest extends TestCase
{
    use RefreshDatabase;

    private function negocio(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['is_exempt' => false], $attrs));
    }

    private function suscribir(User $user, string $status, $endsAt): void
    {
        Subscription::create(['user_id' => $user->id, 'ends_at' => $endsAt, 'status' => $status]);
    }

    public function test_exempt_account_has_no_block_code_even_without_a_subscription(): void
    {
        $this->assertNull($this->negocio(['is_exempt' => true])->codigoDeBloqueoPorSuscripcion());
    }

    public function test_exempt_account_has_no_block_code_even_with_an_expired_subscription(): void
    {
        $user = $this->negocio(['is_exempt' => true]);
        $this->suscribir($user, 'SUSPENDIDO', now()->subYear());

        $this->assertNull($user->codigoDeBloqueoPorSuscripcion());
    }

    public function test_missing_subscription_row_is_no_subscription(): void
    {
        $this->assertSame('NO_SUBSCRIPTION', $this->negocio()->codigoDeBloqueoPorSuscripcion());
    }

    public function test_suspended_wins_over_expiry_even_with_days_left(): void
    {
        $user = $this->negocio();
        $this->suscribir($user, 'SUSPENDIDO', now()->addDays(10));

        $this->assertSame('SUBSCRIPTION_SUSPENDED', $user->codigoDeBloqueoPorSuscripcion());
    }

    public function test_suspended_and_already_past_is_still_suspended(): void
    {
        $user = $this->negocio();
        $this->suscribir($user, 'SUSPENDIDO', now()->subDay());

        $this->assertSame('SUBSCRIPTION_SUSPENDED', $user->codigoDeBloqueoPorSuscripcion());
    }

    public function test_ends_at_in_the_past_is_expired(): void
    {
        $user = $this->negocio();
        $this->suscribir($user, 'ACTIVO', now()->subSecond());

        $this->assertSame('SUBSCRIPTION_EXPIRED', $user->codigoDeBloqueoPorSuscripcion());
    }

    public function test_valid_subscription_has_no_block_code(): void
    {
        $user = $this->negocio();
        $this->suscribir($user, 'ACTIVO', now()->addDay());

        $this->assertNull($user->codigoDeBloqueoPorSuscripcion());
    }
}

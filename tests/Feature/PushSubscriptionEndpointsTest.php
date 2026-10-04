<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NotificationChannels\WebPush\PushSubscription;
use Tests\TestCase;

class PushSubscriptionEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    private function body(array $overrides = []): array
    {
        return array_replace_recursive([
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => 'BKey-public', 'auth' => 'auth-secret'],
        ], $overrides);
    }

    private function salon(): User
    {
        return User::factory()->create(['is_exempt' => true]);
    }

    // ── Auth ─────────────────────────────────────────────────────

    public function test_all_push_endpoints_require_authentication(): void
    {
        $this->getJson('/api/push/public-key')->assertStatus(401);
        $this->postJson('/api/push/subscriptions', $this->body())->assertStatus(401);
        $this->deleteJson('/api/push/subscriptions', ['endpoint' => self::ENDPOINT])->assertStatus(401);
    }

    // ── Public key ───────────────────────────────────────────────

    public function test_public_key_returns_the_configured_vapid_key(): void
    {
        config(['webpush.vapid.public_key' => 'BPublicKeyValue']);

        $this->actingAs($this->salon(), 'sanctum')
            ->getJson('/api/push/public-key')
            ->assertOk()
            ->assertExactJson(['public_key' => 'BPublicKeyValue']);
    }

    public function test_public_key_is_503_when_vapid_is_not_configured(): void
    {
        config(['webpush.vapid.public_key' => null]);

        $this->actingAs($this->salon(), 'sanctum')
            ->getJson('/api/push/public-key')
            ->assertStatus(503);
    }

    // ── Subscribe ────────────────────────────────────────────────

    public function test_subscribe_stores_the_subscription_for_the_authenticated_user(): void
    {
        $user = $this->salon();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/push/subscriptions', $this->body(['user_agent' => 'TestAgent/1.0']))
            ->assertStatus(201);

        $sub = PushSubscription::findByEndpoint(self::ENDPOINT);
        $this->assertNotNull($sub);
        $this->assertSame($user->id, (int) $sub->subscribable_id);
        $this->assertSame(User::class, $sub->subscribable_type);
        $this->assertSame('BKey-public', $sub->public_key);
        $this->assertSame('auth-secret', $sub->auth_token);
        $this->assertSame('TestAgent/1.0', $sub->user_agent);
    }

    public function test_subscribe_is_idempotent_by_endpoint_and_refreshes_the_keys(): void
    {
        $user = $this->salon();

        $this->actingAs($user, 'sanctum')->postJson('/api/push/subscriptions', $this->body())->assertStatus(201);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/push/subscriptions', $this->body(['keys' => ['p256dh' => 'BNew', 'auth' => 'new-auth']]))
            ->assertOk();

        $this->assertSame(1, PushSubscription::count());
        $sub = PushSubscription::findByEndpoint(self::ENDPOINT);
        $this->assertSame('BNew', $sub->public_key);
        $this->assertSame('new-auth', $sub->auth_token);
    }

    public function test_one_user_can_have_several_devices(): void
    {
        $user = $this->salon();

        $this->actingAs($user, 'sanctum')->postJson('/api/push/subscriptions', $this->body())->assertStatus(201);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/push/subscriptions', $this->body(['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/xyz']))
            ->assertStatus(201);

        $this->assertSame(2, $user->pushSubscriptions()->count());
    }

    public function test_subscriptions_are_isolated_per_user(): void
    {
        $a = $this->salon();
        $b = $this->salon();

        $this->actingAs($a, 'sanctum')->postJson('/api/push/subscriptions', $this->body())->assertStatus(201);
        $this->actingAs($b, 'sanctum')
            ->postJson('/api/push/subscriptions', $this->body(['endpoint' => 'https://fcm.googleapis.com/fcm/send/other']))
            ->assertStatus(201);

        $this->assertSame(1, $a->pushSubscriptions()->count());
        $this->assertSame(1, $b->pushSubscriptions()->count());
    }

    public function test_subscribe_validates_the_payload(): void
    {
        $user = $this->salon();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/push/subscriptions', ['endpoint' => 'not-a-url', 'keys' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    }

    // ── Unsubscribe ──────────────────────────────────────────────

    public function test_unsubscribe_removes_only_that_endpoint_of_the_user(): void
    {
        $user = $this->salon();
        $user->updatePushSubscription(self::ENDPOINT, 'k', 't');
        $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/keep', 'k', 't');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/push/subscriptions', ['endpoint' => self::ENDPOINT])
            ->assertOk();

        $this->assertNull(PushSubscription::findByEndpoint(self::ENDPOINT));
        $this->assertNotNull(PushSubscription::findByEndpoint('https://fcm.googleapis.com/fcm/send/keep'));
    }

    public function test_unsubscribe_cannot_remove_another_users_subscription(): void
    {
        $owner = $this->salon();
        $owner->updatePushSubscription(self::ENDPOINT, 'k', 't');

        $this->actingAs($this->salon(), 'sanctum')
            ->deleteJson('/api/push/subscriptions', ['endpoint' => self::ENDPOINT])
            ->assertOk();

        $this->assertNotNull(PushSubscription::findByEndpoint(self::ENDPOINT));
    }

    public function test_unsubscribe_of_an_unknown_endpoint_is_a_harmless_200(): void
    {
        $this->actingAs($this->salon(), 'sanctum')
            ->deleteJson('/api/push/subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/nope'])
            ->assertOk();
    }
}

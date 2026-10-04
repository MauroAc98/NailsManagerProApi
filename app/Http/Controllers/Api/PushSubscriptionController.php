<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NotificationChannels\WebPush\PushSubscription;

class PushSubscriptionController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/push/public-key
    // VAPID public key the frontend passes to pushManager.subscribe()
    // ─────────────────────────────────────────────
    public function publicKey(): JsonResponse
    {
        $key = config('webpush.vapid.public_key');

        if (empty($key)) {
            return response()->json(['message' => 'Las notificaciones push no están configuradas.'], 503);
        }

        return response()->json(['public_key' => $key]);
    }

    // ─────────────────────────────────────────────
    // POST /api/push/subscriptions
    // Idempotent upsert by endpoint for the authenticated account
    // ─────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'in:aes128gcm,aesgcm'],
            'user_agent' => ['nullable', 'string', 'max:255'],
        ]);

        $subscription = $request->user()->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['content_encoding'] ?? null,
        );

        $userAgent = $data['user_agent'] ?? $request->userAgent();
        $subscription->forceFill(['user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null])->save();

        return response()->json(
            ['message' => 'Suscripción guardada.'],
            $subscription->wasRecentlyCreated ? 201 : 200,
        );
    }

    // ─────────────────────────────────────────────
    // DELETE /api/push/subscriptions
    // Removes only the caller's own subscription; unknown endpoint is a no-op
    // ─────────────────────────────────────────────
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH],
        ]);

        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->json(['message' => 'Suscripción eliminada.']);
    }
}

<?php

namespace App\Services\Reservas;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Cloudflare Turnstile. Falla CERRADO: sin token/secret, error o timeout => false. */
class TurnstileVerifier implements ChallengeVerifier
{
    private const URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function verificar(?string $token, string $ipHint): bool
    {
        $secret = (string) config('services.turnstile.secret');
        if ($token === null || $token === '' || $secret === '') {
            return false;
        }

        try {
            $resp = Http::asForm()->timeout(3)->post(self::URL, [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ipHint,
            ]);

            return $resp->successful() && $resp->json('success') === true;
        } catch (Throwable) {
            return false;
        }
    }
}

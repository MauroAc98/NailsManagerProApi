<?php

namespace App\Services\Reservas;

/** Reto anti-bot (p.ej. Turnstile) que se exige al crear un hold. */
interface ChallengeVerifier
{
    public function verificar(?string $token, string $ipHint): bool;
}

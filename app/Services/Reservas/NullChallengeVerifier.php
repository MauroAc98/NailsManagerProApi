<?php

namespace App\Services\Reservas;

/** Default (reservas.challenge.habilitado = false): no exige nada. */
class NullChallengeVerifier implements ChallengeVerifier
{
    public function verificar(?string $token, string $ipHint): bool
    {
        return true;
    }
}

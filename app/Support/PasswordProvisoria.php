<?php

namespace App\Support;

/**
 * Provisional password for a professional account created by an invitation.
 *
 * It is the only credential that travels by email, so it is drawn character by
 * character from the operating system's CSPRNG (random_int) and takes no
 * input: it can never be derived from a name, an email or any other value the
 * invited person or the titular controls. 12 characters over a 57-symbol
 * alphabet is about 70 bits; the account is also protected by the login
 * lockout and the forced change at first login.
 */
final class PasswordProvisoria
{
    public const LONGITUD = 12;

    // Letters and digits only, without the pairs that look alike when read
    // from an email and typed by hand (0/O, 1/l/I).
    public const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public static function generar(): string
    {
        $maximo = strlen(self::ALFABETO) - 1;
        $password = '';

        for ($i = 0; $i < self::LONGITUD; $i++) {
            $password .= self::ALFABETO[random_int(0, $maximo)];
        }

        return $password;
    }
}

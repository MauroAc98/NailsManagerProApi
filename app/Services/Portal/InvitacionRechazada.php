<?php

namespace App\Services\Portal;

use RuntimeException;

/**
 * An invitation the persona titular is not allowed to make. Every case here
 * depends only on the titular's own data (her profile, her email, her
 * professional): none of them depends on whether the invited email already has
 * an account, so none is an enumeration oracle.
 */
final class InvitacionRechazada extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly int $status,
        public readonly string $codigo,
    ) {
        parent::__construct($message);
    }

    public static function perfilDeLaPersonaTitular(): self
    {
        return new self('El perfil de la persona titular del negocio no se puede invitar.', 422, 'PERFIL_DE_LA_PERSONA_TITULAR');
    }

    public static function emailDeLaPersonaTitular(): self
    {
        return new self('Ese es el email de la persona titular del negocio. Usa otro email.', 422, 'EMAIL_DE_LA_PERSONA_TITULAR');
    }

    public static function profesionalInactiva(): self
    {
        return new self('Esa profesional está desactivada. Actívala antes de invitarla.', 422, 'PROFESIONAL_INACTIVA');
    }

    public static function profesionalConAcceso(): self
    {
        return new self('Esa profesional ya tiene acceso al portal. Quita el acceso actual antes de invitar a otro email.', 409, 'PROFESIONAL_CON_ACCESO');
    }
}

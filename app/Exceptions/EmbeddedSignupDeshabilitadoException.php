<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * El master switch `services.whatsapp_es.enabled` está en false: el onboarding
 * de Embedded Signup no está habilitado en este entorno. Se lanza desde
 * GuardEmbeddedSignup, antes de cualquier llamada a Meta. → HTTP 403.
 */
final class EmbeddedSignupDeshabilitadoException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'El onboarding de WhatsApp Embedded Signup no está habilitado.');
    }
}

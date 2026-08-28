<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * El `user_id` no está en `services.whatsapp_es.allowed_user_ids` y
 * `allow_all` es false. Una allowlist vacía falla CERRADO: bloquea a todos.
 * Se lanza desde GuardEmbeddedSignup. → HTTP 403.
 */
final class UsuarioNoHabilitadoException extends HttpException
{
    public function __construct(public readonly int $userId)
    {
        parent::__construct(403, 'Este salón todavía no está habilitado para conectar su número de WhatsApp.');
    }
}

<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El `phone_number_id` que se intenta conectar ya pertenece a la fila de otro
 * salón (violación de la constraint UNIQUE `whatsapp_connections.phone_number_id`).
 *
 * Sólo un `QueryException` con SQLSTATE 23000 que referencia `phone_number_id`
 * se traduce a esta excepción; cualquier otra `QueryException` (deadlock, otra
 * constraint) se relanza intacta → 500. No hay auto-robo: dos salones
 * reclamando un número es un error de operador o una transferencia genuina, y
 * ambos casos necesitan un humano.
 *
 * Lleva SÓLO el id en colisión — nunca la identidad del salón dueño. La
 * divulgación del nombre del dueño (respuesta HTTP 409, sólo para admin) la
 * agrega WhatsappConnectionAdminController; un futuro caller auth:sanctum de
 * tenant recibe esta misma excepción sin fuga cross-tenant.
 */
final class PhoneNumberYaVinculadoException extends RuntimeException
{
    public function __construct(public readonly string $phoneNumberId)
    {
        parent::__construct("El phone_number_id {$phoneNumberId} ya está vinculado a otro salón.");
    }
}

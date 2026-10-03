<?php

namespace App\Services\Reservas;

/** Rechazo de una reprogramacion de grupo (422): mensaje para la duena y `codigo` estable opcional. */
class ReprogramacionException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $codigo = null)
    {
        parent::__construct($message);
    }
}

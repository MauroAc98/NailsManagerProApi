<?php

namespace App\Services\Reservas;

/** Stub por defecto: no envia ni valida nada (fail closed). */
class NullVerificadorWhatsapp implements VerificadorWhatsapp
{
    public function enviarCodigo(string $telefono): bool
    {
        return false;
    }

    public function validarCodigo(string $telefono, string $codigo): bool
    {
        return false;
    }
}

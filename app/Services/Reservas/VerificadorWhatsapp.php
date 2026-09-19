<?php

namespace App\Services\Reservas;

/**
 * Verificacion de titularidad del WhatsApp (codigo de un solo uso). Todavia no
 * hay implementacion real ni endpoint: se enforcea detras de
 * config('reservas.verificacion_habilitada').
 */
interface VerificadorWhatsapp
{
    public function enviarCodigo(string $telefono): bool;

    public function validarCodigo(string $telefono, string $codigo): bool;
}

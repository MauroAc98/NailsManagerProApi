<?php

namespace App\Mail\Concerns;

/**
 * Subject shared by both portal access mails. The negocio name is typed by a
 * user, so control characters (CR/LF would let it start a new header) are
 * collapsed into plain spaces before it reaches the subject line.
 *
 * @property-read string $negocio
 */
trait ConAsuntoDeAccesoAlPortal
{
    protected function asuntoDeAcceso(): string
    {
        $negocio = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $this->negocio));

        return "Te dieron acceso al portal de profesionales de {$negocio}";
    }
}

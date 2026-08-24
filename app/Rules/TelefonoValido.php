<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

// ─────────────────────────────────────────────
// Valida que un teléfono (formato "+<país><número>", ej. "+543764583295")
// sea un número real y completo para uno de los países que el selector de
// país del frontend soporta hoy (lib/phoneUtils.ts -> PAISES). Reemplaza la
// vieja regex (^\+[1-9]\d{7,14}$) que solo contaba dígitos y por eso dejaba
// pasar números truncados como "+54583295" (Argentina sin código de área) —
// bug real que causó un envío de WhatsApp silenciosamente fallido.
// ─────────────────────────────────────────────
class TelefonoValido implements ValidationRule
{
    // Códigos de país soportados por el selector del frontend: AR, BR, UY, PY, CL, BO.
    private const REGIONES_SOPORTADAS = ['AR', 'BR', 'UY', 'PY', 'CL', 'BO'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail($this->mensaje());

            return;
        }

        $phoneUtil = PhoneNumberUtil::getInstance();

        try {
            // El valor ya viene en formato "+<código de país><número>", así que
            // no hace falta (ni conviene) asumir una región por defecto.
            $numero = $phoneUtil->parse($value, null);
        } catch (NumberParseException) {
            $fail($this->mensaje());

            return;
        }

        $region = $phoneUtil->getRegionCodeForNumber($numero);

        if (! in_array($region, self::REGIONES_SOPORTADAS, true)) {
            $fail($this->mensaje());

            return;
        }

        if (! $phoneUtil->isValidNumber($numero)) {
            $fail($this->mensaje());
        }
    }

    private function mensaje(): string
    {
        return 'El teléfono no es válido para el país seleccionado — revisá que esté completo.';
    }
}

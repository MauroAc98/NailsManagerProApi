<?php

namespace App\Actions;

use App\Exceptions\EmbeddedSignupDeshabilitadoException;
use App\Exceptions\UsuarioNoHabilitadoException;
use App\Models\User;

/**
 * Guarda de Advanced Access para el onboarding de Embedded Signup.
 *
 * Vive DENTRO del seam reusable (design §7, A3): EmbeddedSignupService::conectar()
 * la invoca como primer paso, de modo que TODO caller queda gateado — el admin
 * de hoy y un futuro auth:sanctum de self-service por igual. El controller no
 * tiene su propia copia del chequeo; sólo traduce estas excepciones a HTTP.
 *
 * Fail-closed (design §6, A11): con `enabled=true`, una `allowed_user_ids`
 * vacía y `allow_all=false`, se rechaza a TODOS. Habilitar a todos los salones
 * exige el opt-in explícito `WHATSAPP_ES_ALLOW_ALL=true`.
 */
final class GuardEmbeddedSignup
{
    public function verificar(User $user): void
    {
        if (! config('services.whatsapp_es.enabled')) {
            throw new EmbeddedSignupDeshabilitadoException;
        }

        if (config('services.whatsapp_es.allow_all')) {
            return;
        }

        $permitidos = array_map('intval', config('services.whatsapp_es.allowed_user_ids') ?? []);

        if (! in_array((int) $user->id, $permitidos, true)) {
            throw new UsuarioNoHabilitadoException($user->id);
        }
    }
}

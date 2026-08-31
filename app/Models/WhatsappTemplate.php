<?php

namespace App\Models;

// No es un Eloquent Model: la tabla whatsapp_templates (texto
// personalizable por profesional, de la era Evolution) se dropeó — Cloud
// API usa un texto fijo aprobado por Meta, no hay nada que personalizar.
// Esta clase solo agrupa los helpers estáticos que arman el envío real y
// el registro legible de lo que se mandó.
final class WhatsappTemplate
{
    // ── Cloud API: nombre de plantilla Meta por tipo ──────────────
    public static function nombrePlantillaMeta(string $tipo): string
    {
        return match ($tipo) {
            'recordatorio' => 'recordatorio_turno',
            'confirmacion' => 'confirmacion_turno',
            default => '',
        };
    }

    // ── Cloud API: parámetros ordenados {{1}}..{{8}} ───────────────
    // Orden UNIFICADO para ambos tipos: [nombre, negocio, fecha, hora,
    // servicios, direccion, profesional, telefono].
    //
    // {{2}} (negocio) es el nombre del estudio (User::name). {{7}}
    // (profesional) es la persona puntual que atiende ESE turno
    // (Turno::profesional->nombre, tabla `profesionales`) — son dos datos
    // distintos, no el mismo valor repetido.
    //
    // {{6}} (direccion) y {{8}} (telefono) pueden llegar como string vacío
    // si la profesional no cargó el dato — a propósito, sin fallback: Meta
    // rechaza el envío completo si un parámetro de plantilla llega vacío,
    // y ese fallo ya queda cubierto por el manejo de error existente en
    // CloudApiService::enviarPlantilla() (devuelve null → status=failed).
    // Para direccion además hay un guard previo en
    // AuthController::updatePerfil() que impide activar los envíos
    // automáticos sin dirección cargada — así que en la práctica solo
    // debería llegar vacía en cuentas que ya la tenían activada antes de
    // que existiera ese guard.
    //
    // {{7}} (profesional) también puede llegar vacío: turnos viejos de
    // antes del backfill de profesional_id (ver
    // 2026_07_17_100004_backfill_default_profesionales) podrían no tener
    // la relación cargada. Mismo criterio sin-fallback que los demás.
    public static function parametrosCloudApi(
        string $tipo,
        Cliente $cliente,
        Turno $turno,
        User $user,
    ): array {
        $servicios = $turno->servicios->pluck('nombre')->join(' + ');
        $fecha = $turno->fecha_hora->format('d/m');
        $hora = $turno->fecha_hora->format('H:i');
        $direccion = $user->direccion ?? '';
        // Primer nombre solamente (no "María José" completo) — el pedido
        // original era sonar cercano en el mensaje, un nombre compuesto
        // completo ahí se siente más formal/impersonal que cálido.
        $profesional = trim(explode(' ', trim($turno->profesional->nombre ?? ''))[0]);
        // Formateado ("376 500-0000"), no el crudo — mismo criterio que
        // phoneUtils.formatDisplay() en el frontend (usado también en la
        // "historia" de Instagram), para que el teléfono se vea igual en
        // todos los lugares donde el cliente lo ve.
        $telefono = static::formatearTelefono($user->telefono ?? '');

        return match ($tipo) {
            'recordatorio', 'confirmacion' => [$cliente->nombre, $user->name, $fecha, $hora, $servicios, $direccion, $profesional, $telefono],
            default => [],
        };
    }

    // ── Texto legible para el log (WhatsappMensaje.mensaje) ────────
    // Refleja el texto REAL y fijo de la plantilla aprobada en Meta (no
    // hay más texto personalizable) — mismos 8 valores que
    // parametrosCloudApi(), mismo orden, interpolados en el cuerpo real.
    //
    // Los cuerpos de confirmacion_turno y recordatorio_turno se re-aprobaron
    // en Meta con tono "sistema" (2026-08-30): se sacó la línea "Te atiende"
    // y el footer "mensaje automático"; {{7}} (profesional) ahora aparece
    // solo dentro del aviso ⚠️. Se conservan los marcadores *...* de negrita
    // y los emojis para que el log calque lo que ve el cliente.
    public static function mensajeLegible(
        string $tipo,
        Cliente $cliente,
        Turno $turno,
        User $user,
    ): string {
        [$nombre, $negocio, $fecha, $hora, $servicios, $direccion, $profesional, $telefono] = static::parametrosCloudApi($tipo, $cliente, $turno, $user);

        $aviso = "⚠️ Desde este número solo se envían avisos. Si respondés a este mensaje, *{$profesional} no lo recibe y no puede contestarte.*";
        $contacto = "Para consultas o cambios de turno, comunicate al {$telefono} con al menos 24 hs de anticipación.";

        return match ($tipo) {
            // OJO: en recordatorio "🕒 {$hora}" NO lleva "hs" (a diferencia
            // de confirmacion) y el negocio va con el punto DENTRO de la
            // negrita — así quedó aprobado en Meta.
            'recordatorio' => "Hola {$nombre}, te recordamos tu turno de mañana en *{$negocio}.*\n\n🗓️ {$fecha} · 🕒 {$hora}\n✨ {$servicios}\n📍 {$direccion}\n\n{$aviso}\n\n{$contacto}",
            'confirmacion' => "Hola {$nombre}, tu turno en *{$negocio}* quedó confirmado.\n\n🗓️ {$fecha} · 🕒 {$hora} hs\n✨ {$servicios}\n📍 {$direccion}\n\n{$aviso}\n\n{$contacto}",
            default => '',
        };
    }

    // El teléfono viaja DENTRO del cuerpo de la plantilla Meta. WhatsApp
    // solo lo vuelve tappable si está en formato internacional, así que
    // normalizamos cualquier forma de cargar el número (nacional, con o sin
    // código de país, con o sin el 9 de móvil, con separadores) al string
    // canónico "+54 9 AAA NNN-NNNN".
    //
    // Best-effort con fallback crudo: si de la entrada no se puede derivar
    // un número nacional de 10 dígitos (área de 3 + abonado de 7), se
    // devuelve el valor original sin tocar — no queda tappable pero tampoco
    // roto, mismo criterio sin-fallback que el resto de los parámetros
    // (vacío entra, vacío sale; Meta rechaza el envío si llega así).
    private static function formatearTelefono(string $numero): string
    {
        $digitos = preg_replace('/\D/', '', $numero) ?? '';

        // Código de país: si viene con 54 al frente lo sacamos; si no,
        // asumimos que ya es el número nacional (equivale a anteponer 549).
        if (str_starts_with($digitos, '54')) {
            $digitos = substr($digitos, 2);
        }

        // 9 de móvil: "549..." → nacional de 11 dígitos que empieza con 9.
        if (strlen($digitos) === 11 && str_starts_with($digitos, '9')) {
            $digitos = substr($digitos, 1);
        }

        if (strlen($digitos) !== 10) {
            return $numero;
        }

        $area = substr($digitos, 0, 3);
        $central = substr($digitos, 3, 3);
        $final = substr($digitos, 6, 4);

        return "+54 9 {$area} {$central}-{$final}";
    }
}

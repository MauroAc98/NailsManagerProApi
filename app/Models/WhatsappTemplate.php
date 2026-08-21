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
    // parametrosCloudApi(), mismo orden, interpolados en el cuerpo real,
    // más el footer fijo (sin variables) que Meta también le muestra al
    // cliente.
    public static function mensajeLegible(
        string $tipo,
        Cliente $cliente,
        Turno $turno,
        User $user,
    ): string {
        [$nombre, $negocio, $fecha, $hora, $servicios, $direccion, $profesional, $telefono] = static::parametrosCloudApi($tipo, $cliente, $turno, $user);

        $footer = "\n\nEste es un mensaje automático, no hace falta responder.";

        $cuerpo = match ($tipo) {
            'recordatorio' => "Hola {$nombre} ✨\n\n⏰ Recordatorio: tu turno es *mañana* en {$negocio}\n🗓️ {$fecha} · 🕒 {$hora} hs\n✨ {$servicios}\n📍 {$direccion}\n\n*¡Te esperamos!*\n\n📞 ¿Consultas o cambios de turno? Si ya hablaste con {$profesional} previamente, comunicate por ahí. Si no, este es su número: 💬 {$telefono}.\nPor favor, avisar con 24hs de anticipación. ¡Gracias!{$footer}",
            'confirmacion' => "Hola {$nombre} ✨\n\n✅ Turno confirmado en {$negocio}\n🗓️ {$fecha} · 🕒 {$hora} hs\n✨ {$servicios}\n📍 {$direccion}\n\n*¡Te esperamos!*\n\n📞 ¿Consultas o cambios de turno? Si ya hablaste con {$profesional} previamente, comunicate por ahí. Si no, este es su número: 💬 {$telefono}.\nPor favor, avisar con 24hs de anticipación. ¡Gracias!{$footer}",
            default => '',
        };

        return $cuerpo;
    }

    // Puerto 1:1 de phoneUtils.formatDisplay() (nailsmanagerpro-web,
    // lib/phoneUtils.ts) — no asume dónde termina el código de país, agrupa
    // de a 3 dígitos desde la izquierda con los últimos 4 como bloque final
    // separado por guion. Números cortos (<6 dígitos, incluido '') se
    // devuelven tal cual — mismo criterio sin-fallback que el resto de los
    // parámetros: vacío entra, vacío sale, Meta rechaza el envío si llega así.
    private static function formatearTelefono(string $numero): string
    {
        $digitos = preg_replace('/\D/', '', $numero) ?? '';
        if (strlen($digitos) < 6) {
            return $digitos;
        }

        $ultimo = substr($digitos, -4);
        $resto = substr($digitos, 0, -4);
        $grupos = implode(' ', str_split($resto, 3));

        return "{$grupos}-{$ultimo}";
    }
}

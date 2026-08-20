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

    // ── Cloud API: parámetros ordenados {{1}}..{{6}} ───────────────
    // Orden UNIFICADO para ambos tipos: [nombre, negocio, fecha, hora,
    // servicios, telefono].
    //
    // {{6}} (telefono) puede llegar como string vacío si la profesional no
    // cargó su teléfono — a propósito, sin fallback: Meta rechaza el envío
    // completo si un parámetro de plantilla llega vacío, y ese fallo ya
    // queda cubierto por el manejo de error existente en
    // CloudApiService::enviarPlantilla() (devuelve null → status=failed).
    public static function parametrosCloudApi(
        string $tipo,
        Cliente $cliente,
        Turno $turno,
        User $user,
    ): array {
        $servicios = $turno->servicios->pluck('nombre')->join(' + ');
        $fecha = $turno->fecha_hora->format('d/m');
        $hora = $turno->fecha_hora->format('H:i');
        $telefono = $user->telefono ?? '';

        return match ($tipo) {
            'recordatorio', 'confirmacion' => [$cliente->nombre, $user->name, $fecha, $hora, $servicios, $telefono],
            default => [],
        };
    }

    // ── Texto legible para el log (WhatsappMensaje.mensaje) ────────
    // Refleja el texto REAL y fijo de la plantilla aprobada en Meta (no
    // hay más texto personalizable) — mismos 6 valores que
    // parametrosCloudApi(), mismo orden, interpolados en el cuerpo real.
    public static function mensajeLegible(
        string $tipo,
        Cliente $cliente,
        Turno $turno,
        User $user,
    ): string {
        [$nombre, $negocio, $fecha, $hora, $servicios, $telefono] = static::parametrosCloudApi($tipo, $cliente, $turno, $user);

        $cuerpo = match ($tipo) {
            'recordatorio' => "Hola {$nombre} ✨\n\n⏰ Recordatorio: tu turno es *mañana* en {$negocio}\n🗓️ {$fecha} · 🕒 {$hora} hs\n✨ {$servicios}\n\n*¡Te esperamos!*\n\n📞 Ante cualquier duda, escribí al {$telefono}. ¡Gracias!",
            'confirmacion' => "Hola {$nombre} ✨\n\n✅ Turno confirmado en {$negocio}\n🗓️ {$fecha} · 🕒 {$hora} hs\n✨ {$servicios}\n\n*¡Te esperamos!*\n\n📞 Ante cualquier duda, escribí al {$telefono}. ¡Gracias!",
            default => '',
        };

        return $cuerpo;
    }
}

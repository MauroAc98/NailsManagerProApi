<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappTemplate extends Model
{
    protected $fillable = [
        'user_id',
        'tipo',
        'contenido',
    ];

    protected $casts = [
        'tipo' => 'string',
    ];

    // ── Scope de seguridad ──────────────────────────────────────
    public function scopeDelUsuario($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeDelTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    // ── Relación ────────────────────────────────────────────────
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ── Helper para obtener plantilla con valores por defecto ──
    public static function obtenerPlantilla(User $user, string $tipo): string
    {
        $template = static::delUsuario($user)
            ->delTipo($tipo)
            ->first();

        if ($template) {
            return $template->contenido;
        }

        // Valores por defecto según el tipo, en el idioma de la profesional
        return static::plantillaDefault($tipo, $user->locale);
    }

    // ── Plantillas por defecto ──────────────────────────────────
    public static function plantillaDefault(string $tipo, ?string $locale = null): string
    {
        if ($locale === 'pt-BR') {
            return match ($tipo) {
                'recordatorio' => 'Oi {nombre} 💅 Passando para lembrar do seu horário em {fecha} às {hora} para {servicios}. Te espero!',
                'confirmacion' => 'Oi {nombre} 💅 Seu horário de {servicios} está confirmado para {fecha} às {hora}. Te espero!',
                default => '',
            };
        }

        return match ($tipo) {
            'recordatorio' => 'Hola {nombre} 💅 Te recuerdo tu turno el {fecha} a las {hora} para {servicios}. ¡Te espero!',
            'confirmacion' => 'Hola {nombre} 💅 Tu turno de {servicios} está confirmado para el {fecha} a las {hora}. ¡Te espero!',
            default => '',
        };
    }

    // ── Cloud API: nombre de plantilla Meta por tipo ──────────────
    // Solo existen versiones en español (es_AR) aprobadas — los callers
    // deben mantener el fallback a Evolution para locale pt-BR.
    public static function nombrePlantillaMeta(string $tipo): string
    {
        return match ($tipo) {
            'recordatorio' => 'recordatorio_turno',
            'confirmacion' => 'confirmacion_turno',
            default => '',
        };
    }

    // ── Cloud API: parámetros ordenados {{1}}..{{5}} por tipo ─────
    // Mismos valores/formato que procesarPlantilla() usa para Evolution
    // (servicios unidos con " + ", fecha d/m, hora H:i) — la plantilla
    // aprobada en Meta espera ese mismo shape, no el formato de ejemplo
    // que se usó solo para la revisión del template.
    public static function parametrosCloudApi(
        string $tipo,
        Cliente $cliente,
        Turno $turno,
        User $user,
    ): array {
        $servicios = $turno->servicios->pluck('nombre')->join(' + ');
        $fecha = $turno->fecha_hora->format('d/m');
        $hora = $turno->fecha_hora->format('H:i');

        return match ($tipo) {
            'recordatorio' => [$cliente->nombre, $user->name, $fecha, $hora, $servicios],
            'confirmacion' => [$cliente->nombre, $user->name, $servicios, $fecha, $hora],
            default => [],
        };
    }

    // ── Reemplazar placeholders ─────────────────────────────────
    public static function procesarPlantilla(
        string $plantilla,
        Cliente $cliente,
        Turno $turno,
        User $user,
    ): string {
        $servicios = $turno->servicios->pluck('nombre')->join(' + ');
        $fecha = $turno->fecha_hora->format('d/m');
        $hora = $turno->fecha_hora->format('H:i');

        return strtr($plantilla, [
            '{nombre}'   => $cliente->nombre,
            '{apellido}' => $cliente->apellido ?? '',
            '{servicios}' => $servicios,
            '{fecha}' => $fecha,
            '{hora}' => $hora,
            '{negocio}' => $user->name,
            '{profesional}' => $turno->profesional?->nombre ?? $user->name,
        ]);
    }
}

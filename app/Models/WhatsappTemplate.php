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

        // Valores por defecto según el tipo
        return static::plantillaDefault($tipo);
    }

    // ── Plantillas por defecto ──────────────────────────────────
    public static function plantillaDefault(string $tipo): string
    {
        return match ($tipo) {
            'recordatorio' => 'Hola {nombre} 💅 Te recuerdo tu turno el {fecha} a las {hora} para {servicios}. ¡Te espero!',
            'confirmacion' => 'Hola {nombre} 💅 Tu turno de {servicios} está confirmado para el {fecha} a las {hora}. ¡Te espero!',
            default => '',
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
        ]);
    }
}

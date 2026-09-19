<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Reserva web = "hold" de un horario + reserva online. Estados:
 * held | pending_payment | confirmed | expired | cancelled
 * (mas los legacy accepted / rejected del panel de aceptacion manual).
 *
 * `fecha` y `slot_hora` son strings de pared en la zona de la app (sin cast
 * datetime) y `expira_en` / `confirmada_en` son epoch en segundos: asi el
 * indice unico parcial y las comparaciones no dependen de la zona horaria.
 */
class ReservaWeb extends Model
{
    public const ESTADOS_BLOQUEANTES = ['held', 'pending_payment'];

    protected $table = 'reservas_web';
    protected $fillable = [
        'user_id',
        'profesional_id',
        'public_token',
        'nombre_completo',
        'telefono',
        'nombre',
        'apellido',
        'nota',
        'servicio_ids',
        'fecha',
        'slot_hora',
        'duracion_total_minutos',
        'estado',
        'expira_en',
        'pago_extendido',
        'alta_ocupacion',
        'device_hash',
        'idempotency_key',
        'requiere_reembolso',
        'confirmada_en',
        'motivo_cierre',
    ];

    protected function casts(): array
    {
        return [
            'servicio_ids'           => 'array',
            'duracion_total_minutos' => 'integer',
            'expira_en'              => 'integer',
            'confirmada_en'          => 'integer',
            'pago_extendido'         => 'boolean',
            'alta_ocupacion'         => 'boolean',
            'requiere_reembolso'     => 'boolean',
        ];
    }

    /** Token opaco de la URL publica (~238 bits). Nunca se exponen ids. */
    public static function generarToken(): string
    {
        return Str::random(40);
    }

    // ── Scopes de seguridad ──────────────────────────────────────
    public function scopeDelUsuario($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Legacy (panel de aceptacion manual): solo las filas sin token publico,
     * asi los holds nuevos en pending_payment no se filtran al panel.
     */
    public function scopePendientes($query)
    {
        return $query->where('estado', 'pending_payment')->whereNull('public_token');
    }

    public function scopeBloqueantes($query)
    {
        return $query->whereIn('estado', self::ESTADOS_BLOQUEANTES);
    }

    /** Holds que hoy bloquean: estado bloqueante Y expira_en en el futuro. */
    public function scopeVivos($query, int $ahoraEpoch)
    {
        return $query->bloqueantes()->where('expira_en', '>', $ahoraEpoch);
    }

    public function scopeDelaFecha($query, string $fecha)
    {
        return $query->where('fecha', $fecha);
    }

    // ── Relaciones ───────────────────────────────────────────────
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function profesional()
    {
        return $this->belongsTo(Profesional::class);
    }

    public function pagoSena()
    {
        return $this->hasOne(PagoSena::class);
    }

    public function turno()
    {
        return $this->hasOne(Turno::class);
    }
}

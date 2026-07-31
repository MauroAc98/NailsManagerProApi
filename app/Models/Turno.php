<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Turno extends Model
{
    protected $fillable = [
        'user_id',
        'profesional_id',
        'cliente_id',
        'reserva_web_id',
        'fecha_hora',
        'duracion_total_minutos',
        'estado',
        'motivo_cancelacion',
        'cancelado_en',
        'origen',
        'notas',
    ];

    /**
     * Evita que Carbon serialice las fechas convertidas a UTC (con "Z").
     * Así fecha_hora se devuelve tal cual está guardada
     * (hora local de Argentina), sin desfases en el frontend.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:s');
    }

    protected function casts(): array
    {
        return [
            'fecha_hora' => 'datetime',
            'cancelado_en' => 'datetime',
            'duracion_total_minutos' => 'integer',
        ];
    }

    // ── Scopes de seguridad ──────────────────────────────────────
    public function scopeDelUsuario($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeConfirmados($query)
    {
        return $query->where('estado', 'confirmado');
    }

    public function scopeDelaFecha($query, string $fecha)
    {
        return $query->whereDate('fecha_hora', $fecha);
    }

    public function scopeDelRango($query, string $desde, string $hasta)
    {
        return $query->whereDate('fecha_hora', '>=', $desde)
            ->whereDate('fecha_hora', '<=', $hasta);
    }

    // ── Helpers de estado ────────────────────────────────────────
    public function estaConfirmado(): bool
    {
        return $this->estado === 'confirmado';
    }

    public function fueCreadoDesdeWeb(): bool
    {
        return $this->origen === 'web';
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

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function reservaWeb()
    {
        return $this->belongsTo(ReservaWeb::class);
    }

    public function servicios()
    {
        return $this->belongsToMany(Servicio::class, 'turno_servicio')->withPivot('precio');
    }
}

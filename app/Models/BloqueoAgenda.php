<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bloqueo de agenda: fecha puntual en la que una profesional (o el salon
 * entero, cuando profesional_id es null) no atiende. Dia completo cuando
 * hora_desde/hora_hasta son ambos null; parcial cuando ambos vienen
 * cargados (invariante validado en BloqueoAgendaController::store).
 */
class BloqueoAgenda extends Model
{
    protected $table = 'bloqueos_agenda';

    protected $fillable = [
        'user_id',
        'profesional_id',
        'fecha',
        'hora_desde',
        'hora_hasta',
        'motivo',
    ];

    protected function casts(): array
    {
        return [
            'fecha'      => 'date:Y-m-d',
            'hora_desde' => 'string',
            'hora_hasta' => 'string',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────
    public function scopeDelUsuario($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeParaFecha($query, string $fecha)
    {
        return $query->where('fecha', $fecha);
    }

    // Bloqueos que aplican a $profesionalId: los salon-wide (profesional_id
    // null) SIEMPRE aplican, mas los especificos de esa profesional.
    public function scopeAplicaA($query, ?int $profesionalId)
    {
        return $query->where(function ($q) use ($profesionalId) {
            $q->whereNull('profesional_id');
            if ($profesionalId) {
                $q->orWhere('profesional_id', $profesionalId);
            }
        });
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
}

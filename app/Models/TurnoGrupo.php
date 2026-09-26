<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Agrupa los Turnos (uno por tramo) de una misma reserva multi-profesional.
 * `modo` es paralelo | secuencia; `precio_promo` es el precio total de la
 * promo cuando el grupo nace de una promo con componentes.
 */
class TurnoGrupo extends Model
{
    protected $table = 'turno_grupos';

    protected $fillable = [
        'promo_servicio_id',
        'reserva_web_id',
        'modo',
        'precio_promo',
    ];

    protected function casts(): array
    {
        return [
            'precio_promo' => 'decimal:2',
        ];
    }

    public function turnos()
    {
        return $this->hasMany(Turno::class, 'grupo_id');
    }

    public function promo()
    {
        return $this->belongsTo(Servicio::class, 'promo_servicio_id');
    }

    public function reservaWeb()
    {
        return $this->belongsTo(ReservaWeb::class);
    }
}

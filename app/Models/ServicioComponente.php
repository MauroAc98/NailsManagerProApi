<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un componente de una promo: un servicio individual ejecutado por una
 * profesional fija, en una posicion (`orden`) dentro de la promo.
 */
class ServicioComponente extends Model
{
    protected $table = 'servicio_componentes';

    protected $fillable = [
        'servicio_id',
        'componente_servicio_id',
        'profesional_id',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
        ];
    }

    public function promo()
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }

    public function componenteServicio()
    {
        return $this->belongsTo(Servicio::class, 'componente_servicio_id');
    }

    public function profesional()
    {
        return $this->belongsTo(Profesional::class);
    }
}

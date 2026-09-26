<?php

namespace App\Services\Servicios;

use App\Models\Servicio;

/**
 * Promo components (combo-multi-profesional): reads the configured components
 * of a promo. A promo without components is a legacy promo.
 */
class PromoComponentes
{
    /**
     * Additive keys appended to the GET-one servicio response. A servicio
     * without components yields "nothing configured" values.
     */
    public function detalle(Servicio $servicio): array
    {
        return [
            'componentes' => [],
            'problemas' => [],
            'duracion_derivada' => null,
            'precio_componentes' => null,
        ];
    }
}

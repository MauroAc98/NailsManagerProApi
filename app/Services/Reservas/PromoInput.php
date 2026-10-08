<?php

namespace App\Services\Reservas;

/**
 * Promo componentizada como dato plano. $componentes viene ordenado por `orden`;
 * cada item: servicio_id, profesional_id, duracion_minutos, precio (standalone).
 * $precioPromo null = precio por defecto (suma de los precios standalone).
 * $servicioId es el id del servicio-promo (para saber de que promo nace el grupo).
 */
final class PromoInput
{
    /** @param  array<int, array{servicio_id: int, profesional_id: int, duracion_minutos: int, precio: int}>  $componentes */
    public function __construct(
        public readonly string $modo,
        public readonly array $componentes,
        public readonly ?int $precioPromo = null,
        public readonly ?int $servicioId = null,
    ) {
    }
}

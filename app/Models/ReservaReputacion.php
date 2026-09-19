<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contadores anti-abuso por salon. Solo guarda HASHES (hmac del token de
 * dispositivo / del telefono normalizado): nunca el valor en claro.
 */
class ReservaReputacion extends Model
{
    protected $table = 'reserva_reputaciones';

    protected $fillable = [
        'user_id',
        'kind',
        'key_hash',
        'expirados_sin_pago',
        'bloqueado_hasta',
        'verificado_hasta',
        'ultimo_expirado_en',
    ];

    protected function casts(): array
    {
        return [
            'expirados_sin_pago' => 'integer',
            'bloqueado_hasta' => 'integer',
            'verificado_hasta' => 'integer',
            'ultimo_expirado_en' => 'integer',
        ];
    }
}

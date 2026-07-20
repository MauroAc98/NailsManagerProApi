<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMensaje extends Model
{
    protected $table = 'whatsapp_mensajes';

    protected $fillable = [
        'user_id',
        'turno_id',
        'numero',
        'mensaje',
        'tipo',
        'message_id',
        'status',
        'intentos',
        'ultimo_intento',
    ];

    protected $casts = [
        'ultimo_intento' => 'datetime',
        'intentos'       => 'integer',
    ];

    // ── Relaciones ───────────────────────────────────────────────
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    // ── Scopes ───────────────────────────────────────────────────

    // Mensajes cuyo envío falló de verdad (Evolution no devolvió message_id),
    // hace más de 5 minutos, con menos de 3 intentos. No se incluye 'pending':
    // ese estado significa que Evolution ya aceptó el mensaje y está esperando
    // la confirmación de entrega al dispositivo del destinatario, no que el
    // envío falló — reenviarlo ahí duplica el mensaje cuando el teléfono
    // simplemente estaba apagado o sin señal.
    public function scopePendientesParaReenviar($query)
    {
        return $query
            ->where('status', 'failed')
            ->where('ultimo_intento', '<=', now()->subMinutes(5))
            ->where('intentos', '<', 3);
    }
}
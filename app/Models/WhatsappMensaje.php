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
        'provider',
        'mensaje',
        'tipo',
        'message_id',
        'status',
        'status_event_at',
        'respuesta_api',
        'status_code',
        'error_code',
        'error_titulo',
        'error_detalle',
    ];

    protected $casts = [
        'respuesta_api'   => 'array',
        'status_code'     => 'integer',
        'error_code'      => 'integer',
        // Entero Unix crudo (epoch de Meta), NO 'datetime' — ver comentario
        // en la migración add_status_event_at: evita un bug de timezone
        // real entre escritura/lectura del cast datetime de Eloquent.
        'status_event_at' => 'integer',
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
}
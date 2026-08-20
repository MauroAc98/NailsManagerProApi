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
        'respuesta_api',
        'status_code',
    ];

    protected $casts = [
        'respuesta_api' => 'array',
        'status_code'   => 'integer',
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
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

    // Mensajes pendientes de más de 5 minutos con menos de 3 intentos
    public function scopePendientesParaReenviar($query)
    {
        return $query
            ->where('status', 'pending')
            ->where('ultimo_intento', '<=', now()->subMinutes(5))
            ->where('intentos', '<', 3);
    }
}
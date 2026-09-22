<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserMpCredential extends Model
{
    protected $fillable = [
        'user_id',
        'mp_access_token',
        'mp_user_id',
        'webhook_ruteo',
    ];

    protected static function booted(): void
    {
        // Autogenerado siempre, nunca a mano: cargar credenciales manualmente
        // (fase 1, sin UI) no puede depender de que alguien se acuerde de este
        // paso — ver comentario en la migracion add_webhook_ruteo.
        static::creating(function (self $credencial) {
            $credencial->webhook_ruteo ??= Str::random(40);
        });
    }

    /**
     * Sin esto, un toJson()/toArray() accidental de la relacion (alcanzable
     * desde un User serializado) emitiria el token de la cuenta de Mercado
     * Pago del negocio DESCIFRADO en una respuesta HTTP. Mismo criterio que
     * WhatsappConnection::$hidden.
     */
    protected $hidden = ['mp_access_token'];

    protected function casts(): array
    {
        return [
            'mp_access_token' => 'encrypted',
        ];
    }

    // ── Relaciones ───────────────────────────────────────────────
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
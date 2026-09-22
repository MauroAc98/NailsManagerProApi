<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserMpCredential extends Model
{
    protected $fillable = [
        'user_id',
        'mp_access_token',
        'mp_user_id',
    ];

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
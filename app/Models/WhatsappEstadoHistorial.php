<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappEstadoHistorial extends Model
{
    protected $fillable = [
        'user_id',
        'estado',
        'status_reason',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappEstadoHistorial extends Model
{
    protected $table = 'whatsapp_estado_historiales';

    protected $fillable = [
        'user_id',
        'estado',
        'status_reason',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function registrar(User $user, string $estado, ?int $statusReason = null): void
    {
        static::create([
            'user_id' => $user->id,
            'estado' => $estado,
            'status_reason' => $statusReason,
        ]);
    }
}

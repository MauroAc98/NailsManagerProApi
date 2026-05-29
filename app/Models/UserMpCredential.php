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
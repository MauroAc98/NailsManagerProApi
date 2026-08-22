<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

// Identidad disjunta de User (tenant): un AdminUser nunca puede autenticar
// contra el guard `sanctum` (provider `users`) ni viceversa — ver
// config/auth.php y el bug que corrige (provider pineado en ambos guards).
class AdminUser extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}

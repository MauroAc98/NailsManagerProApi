<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    protected $fillable = [
        'user_id',
        'nombre',
        'apellido',
        'telefono',
        'activo',
        'whatsapp_opt_out',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'whatsapp_opt_out' => 'boolean',
        ];
    }

    // ── Scope de seguridad ───────────────────────────────────────
    public function scopeDelUsuario($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    // ── Relaciones ───────────────────────────────────────────────
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ── Matching de teléfono entrante (webhook) ────────────────────
    // El remoteJid que manda el webhook viene en formato JID de WhatsApp
    // (incluye código de país y, para celulares argentinos, un "9" que
    // WhatsApp inserta y que no necesariamente está guardado igual en
    // `telefono`). Comparar los últimos 10 dígitos (largo de un celular
    // argentino sin código de país) evita depender de ese formato.
    public static function porTelefono(User $user, string $telefonoEntrante): ?self
    {
        $entrante = substr(preg_replace('/\D/', '', $telefonoEntrante), -10);

        if ($entrante === '') {
            return null;
        }

        return static::delUsuario($user)
            ->get()
            ->first(fn (self $cliente) => substr(preg_replace('/\D/', '', (string) $cliente->telefono), -10) === $entrante);
    }

    public function turnos()
    {
        return $this->hasMany(Turno::class);
    }
}
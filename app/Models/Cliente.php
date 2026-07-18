<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
    //
    // Devuelve TODOS los que matchean, no solo el primero: la unicidad de
    // `telefono` es por string exacto, así que la misma persona puede
    // existir dos veces con "+543765252395" y "+5493765252395" (ambos
    // válidos, mismos últimos 10 dígitos) — si solo actualizáramos el
    // primero, la otra ficha seguiría recibiendo mensajes después del BAJA.
    public static function todosPorTelefono(User $user, string $telefonoEntrante): Collection
    {
        $entrante = substr(preg_replace('/\D/', '', $telefonoEntrante), -10);

        if ($entrante === '') {
            return collect();
        }

        return static::delUsuario($user)
            ->get()
            ->filter(fn (self $cliente) => substr(preg_replace('/\D/', '', (string) $cliente->telefono), -10) === $entrante)
            ->values();
    }

    public function turnos()
    {
        return $this->hasMany(Turno::class);
    }
}
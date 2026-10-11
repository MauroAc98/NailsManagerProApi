<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CategoriaServicio extends Model
{
    protected $table = 'categorias_servicio';

    protected $fillable = [
        'user_id',
        'nombre',
        'fondo_historia_path',
    ];

    protected $appends = ['fondo_historia_url'];
    protected $hidden  = ['fondo_historia_path'];

    // URL publica del fondo de historia de la categoria, o null. Mismo
    // criterio que Profesional: el path relativo nunca se expone.
    protected function fondoHistoriaUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->fondo_historia_path
                ? Storage::disk('public')->url($this->fondo_historia_path)
                : null,
        );
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

    public function servicios()
    {
        return $this->hasMany(Servicio::class, 'categoria_id');
    }
}

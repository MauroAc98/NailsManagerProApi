<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Profesional extends Model
{
    protected $table = 'profesionales';

    protected $fillable = [
        'user_id',
        'nombre',
        'color',
        'activo',
        'fondo_historia_path',
        'historia_precios_template_id',
    ];

    protected $appends = ['fondo_historia_url'];
    protected $hidden  = ['fondo_historia_path'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    // URL pública del fondo fijo guardado para "generar historia", o null si
    // esta profesional no tiene uno guardado. fondo_historia_path nunca se
    // expone directo (es una ruta relativa del disco 'public', no algo que
    // el frontend necesite armar a mano).
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

    // ─────────────────────────────────────────────
    // Resuelve el Profesional a usar para una request.
    // Si se manda un id explícito, lo valida contra la cuenta (404 si no
    // existe o es de otro usuario). Si se omite, resuelve al profesional
    // default de la cuenta (el más antiguo) — este es el mecanismo de
    // backward-compat: la app RN nunca manda profesional_id.
    // ─────────────────────────────────────────────
    public static function resolverParaUsuario(User $user, ?int $profesionalIdSolicitado): ?self
    {
        if ($profesionalIdSolicitado) {
            return $user->profesionales()->findOrFail($profesionalIdSolicitado);
        }

        return static::where('user_id', $user->id)->oldest('id')->first();
    }

    // ── Relaciones ───────────────────────────────────────────────
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function servicios()
    {
        return $this->belongsToMany(Servicio::class, 'profesional_servicio');
    }

    public function slotsDisponibles()
    {
        return $this->hasMany(SlotDisponible::class);
    }

    public function turnos()
    {
        return $this->hasMany(Turno::class);
    }

    // Fotos de la "historia de precios" (dynamic price story). Se exponen
    // ordenadas por 'orden' — el frontend arma la plantilla elegida
    // (historia_precios_template_id) respetando ese orden tal cual llega.
    public function historiaPreciosFotos()
    {
        return $this->hasMany(HistoriaPrecioFoto::class)->orderBy('orden');
    }
}

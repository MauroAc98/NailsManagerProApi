<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class HistoriaPrecioFoto extends Model
{
    protected $table = 'historia_precios_fotos';

    protected $fillable = [
        'profesional_id',
        'path',
        'orden',
    ];

    protected $appends = ['url'];
    protected $hidden  = ['path', 'profesional_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
        ];
    }

    // URL pública de la foto — el frontend nunca ve la ruta relativa del
    // disco 'public', solo la URL absoluta ya armada.
    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn () => Storage::disk('public')->url($this->path),
        );
    }

    public function profesional()
    {
        return $this->belongsTo(Profesional::class);
    }
}

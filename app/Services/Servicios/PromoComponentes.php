<?php

namespace App\Services\Servicios;

use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Promo components (combo-multi-profesional): configures and reads the
 * components of a promo. A promo without components is a legacy promo.
 */
class PromoComponentes
{
    public const MODO_PARALELO = 'paralelo';
    public const MODO_SECUENCIA = 'secuencia';

    /**
     * "Atiende en paralelo" only means something with more than one active
     * professional; with a single one it is treated as OFF.
     */
    public function paraleloHabilitado(User $user): bool
    {
        return $user->atiende_en_paralelo
            && $user->profesionales()->where('activo', true)->count() > 1;
    }

    /**
     * Replaces the components of a promo. An empty list reverts the promo to
     * legacy (no components, no mode). Otherwise the duration is derived
     * (paralelo = max, secuencia = sum) and the price defaults to the sum of
     * the components' standalone prices unless overridden.
     *
     * @param  array<int, array{servicio_id:int, profesional_id:int}>  $componentes  in execution order
     *
     * @throws ValidationException
     */
    public function reemplazar(Servicio $promo, ?string $modo, array $componentes, ?float $precio): void
    {
        if ($componentes === []) {
            DB::transaction(function () use ($promo) {
                $promo->componentes()->delete();
                $promo->update(['modo_promo' => null]);
            });

            return;
        }

        $servicios = Servicio::where('user_id', $promo->user_id)
            ->whereIn('id', array_column($componentes, 'servicio_id'))
            ->get()->keyBy('id');
        $profesionales = Profesional::where('user_id', $promo->user_id)
            ->whereIn('id', array_column($componentes, 'profesional_id'))
            ->get()->keyBy('id');

        $this->validarComposicion($modo, $componentes, $servicios, $profesionales);

        $duraciones = collect($componentes)->map(fn ($c) => $servicios[$c['servicio_id']]->duracion_minutos);
        $suma = round(collect($componentes)->sum(fn ($c) => (float) $servicios[$c['servicio_id']]->precio), 2);

        DB::transaction(function () use ($promo, $modo, $componentes, $duraciones, $suma, $precio) {
            $promo->componentes()->delete();
            foreach ($componentes as $indice => $componente) {
                $promo->componentes()->create([
                    'componente_servicio_id' => $componente['servicio_id'],
                    'profesional_id' => $componente['profesional_id'],
                    'orden' => $indice + 1,
                ]);
            }
            $promo->update([
                'modo_promo' => $modo,
                'duracion_minutos' => $modo === self::MODO_PARALELO ? $duraciones->max() : $duraciones->sum(),
                'precio' => $precio ?? $suma,
            ]);
        });
    }

    /**
     * Each component's professional must offer its service; in paralelo mode
     * the professionals must be distinct (secuencia allows repeats).
     */
    private function validarComposicion(?string $modo, array $componentes, $servicios, $profesionales): void
    {
        $errores = [];
        $vistas = [];

        foreach ($componentes as $indice => $componente) {
            $servicio = $servicios[$componente['servicio_id']];
            $profesional = $profesionales[$componente['profesional_id']];
            $clave = "componentes.{$indice}.profesional_id";

            if (! $profesional->servicios()->whereKey($servicio->id)->exists()) {
                $errores[$clave] = ["{$profesional->nombre} no ofrece el servicio {$servicio->nombre}."];
            } elseif ($modo === self::MODO_PARALELO && isset($vistas[$profesional->id])) {
                $errores[$clave] = ["{$profesional->nombre} ya está en otro componente: en paralelo cada componente necesita una profesional distinta."];
            }

            $vistas[$profesional->id] = true;
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * Additive keys appended to the GET-one servicio response. A servicio
     * without components yields "nothing configured" values.
     */
    public function detalle(Servicio $servicio): array
    {
        return [
            'componentes' => [],
            'problemas' => [],
            'duracion_derivada' => null,
            'precio_componentes' => null,
        ];
    }
}

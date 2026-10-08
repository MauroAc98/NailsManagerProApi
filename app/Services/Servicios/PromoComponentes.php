<?php

namespace App\Services\Servicios;

use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\ServicioComponente;
use App\Models\User;
use App\Services\Reservas\AlineacionSlots;
use App\Services\Reservas\DisponibilidadService;
use App\Services\Reservas\PromoInput;
use App\Services\Reservas\TramosResolver;
use Illuminate\Support\Collection;
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

    private const SIN_ALINEACION = ['inicios_validos' => [], 'descartados' => []];

    public function __construct(
        private DisponibilidadService $disponibilidad,
        private TramosResolver $tramos,
        private AlineacionSlots $alineacion,
    ) {
    }

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
     * Builds a PromoInput from a promo's own current components and price
     * (combo-multi-profesional, PR 3a3). `$componentes` may be passed
     * already eager-loaded (see detalle()/alineacionSlots()) to avoid a
     * second query; otherwise it is fetched here. The promo's own `precio`
     * is always the price to prorate: reemplazar() keeps it equal to the
     * override when set, or the standalone sum otherwise, so passing it
     * here (instead of null) never changes the default-price result.
     */
    public function promoInput(Servicio $promo, ?Collection $componentes = null): PromoInput
    {
        $componentes ??= $promo->componentes()->with('componenteServicio')->get();

        return new PromoInput(
            $promo->modo_promo ?? self::MODO_SECUENCIA,
            $componentes->map(fn ($c) => [
                'servicio_id' => $c->componente_servicio_id,
                'profesional_id' => $c->profesional_id,
                'duracion_minutos' => $c->componenteServicio->duracion_minutos,
                'precio' => (int) round((float) $c->componenteServicio->precio),
            ])->all(),
            (int) round((float) $promo->precio),
            $promo->id,
        );
    }

    /**
     * Promos that use the servicio as a component (blocks deleting it: the
     * component FK is restrictive).
     *
     * @return array<int, array{id:int, nombre:string}>
     */
    public function promosQueUsan(Servicio $servicio): array
    {
        return Servicio::whereIn('id', ServicioComponente::where('componente_servicio_id', $servicio->id)->select('servicio_id'))
            ->orderBy('id')
            ->get(['id', 'nombre'])
            ->map(fn ($promo) => ['id' => $promo->id, 'nombre' => $promo->nombre])
            ->all();
    }

    /**
     * Additive keys appended to the GET-one servicio response. A servicio
     * without components yields "nothing configured" values. The derived
     * duration and price are computed live from the components' current
     * services, so they stay right even when the persisted values go stale.
     * A promo with `problemas` is not bookable online.
     */
    public function detalle(Servicio $servicio): array
    {
        $componentes = $servicio->componentes()->with(['componenteServicio', 'profesional'])->get();

        if ($componentes->isEmpty()) {
            return [
                'componentes' => [],
                'problemas' => [],
                'alineacion_slots' => self::SIN_ALINEACION,
                'duracion_derivada' => null,
                'precio_componentes' => null,
            ];
        }

        $duraciones = $componentes->map(fn ($c) => $c->componenteServicio->duracion_minutos);
        $problemas = $this->problemas($componentes);

        // Slot alignment only means something for a promo that is bookable in
        // principle; with a broken component it is already flagged as a problema.
        $alineacion = $problemas === [] ? $this->alineacionSlots($servicio, $componentes) : self::SIN_ALINEACION;
        if ($problemas === [] && $alineacion['inicios_validos'] === []) {
            $problemas[] = [
                'codigo' => 'sin_inicios_alineados', 'orden' => null, 'profesional_id' => null, 'servicio_id' => null,
                'mensaje' => 'Ningún horario de esta promo coincide con los slots de todas las profesionales: no se ofrecerá online.',
            ];
        }

        return [
            'componentes' => $componentes->map(fn ($c) => [
                'orden' => $c->orden,
                'servicio_id' => $c->componente_servicio_id,
                'nombre' => $c->componenteServicio->nombre,
                'duracion_minutos' => $c->componenteServicio->duracion_minutos,
                'precio' => $c->componenteServicio->precio,
                'profesional_id' => $c->profesional_id,
                'profesional_nombre' => $c->profesional->nombre,
            ])->all(),
            'problemas' => $problemas,
            'alineacion_slots' => $alineacion,
            'duracion_derivada' => $servicio->modo_promo === self::MODO_PARALELO ? $duraciones->max() : $duraciones->sum(),
            'precio_componentes' => round($componentes->sum(fn ($c) => (float) $c->componenteServicio->precio), 2),
        ];
    }

    /**
     * Client-flow slot rule applied to the promo's own components (loose
     * services are never part of this analysis): which starts of the lead
     * professional can be offered and which are dropped, and why.
     *
     * @return array{inicios_validos: array<int, string>, descartados: array<int, array>}
     */
    private function alineacionSlots(Servicio $promo, $componentes): array
    {
        $plan = $this->tramos->planes($this->promoInput($promo, $componentes), [], false)[0];

        $slots = [];
        $nombres = [];
        foreach ($componentes as $componente) {
            $slots[$componente->profesional_id] = $this->disponibilidad->horasActivas($promo->user, $componente->profesional);
            $nombres[$componente->profesional_id] = $componente->profesional->nombre;
        }

        return $this->alineacion->analizarPromo($plan->tramos, $slots, $nombres);
    }

    /**
     * Configuration warnings: a component whose professional was deactivated
     * or no longer offers the component's service.
     *
     * @return array<int, array{codigo:string, orden:int, profesional_id:int, servicio_id:int, mensaje:string}>
     */
    private function problemas($componentes): array
    {
        $problemas = [];

        foreach ($componentes as $componente) {
            $profesional = $componente->profesional;
            $servicio = $componente->componenteServicio;
            $base = [
                'orden' => $componente->orden,
                'profesional_id' => $profesional->id,
                'servicio_id' => $servicio->id,
            ];

            if (! $profesional->activo) {
                $problemas[] = ['codigo' => 'profesional_inactiva'] + $base + [
                    'mensaje' => "{$profesional->nombre} está inactiva y no puede hacer {$servicio->nombre}.",
                ];
            }

            if (! $profesional->servicios()->whereKey($servicio->id)->exists()) {
                $problemas[] = ['codigo' => 'servicio_desvinculado'] + $base + [
                    'mensaje' => "{$profesional->nombre} ya no ofrece el servicio {$servicio->nombre}.",
                ];
            }
        }

        return $problemas;
    }
}

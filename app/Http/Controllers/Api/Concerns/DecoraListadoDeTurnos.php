<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Turno;
use App\Models\TurnoGrupo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Forma única de un turno en un listado (GET /turnos, GET /cobros): mismas
 * relaciones, mismas claves extra. Si el shape cambia, cambia en los dos.
 */
trait DecoraListadoDeTurnos
{
    /** Relaciones que arma cada turno de un listado. */
    private function relacionesDeListado(): array
    {
        return [
            'cliente',
            'servicios',
            'reservaWeb',
            'whatsappMensajes' => fn ($q) => $q->where('tipo', 'confirmacion')->latest()->limit(1)->select(['id', 'turno_id', 'status']),
        ];
    }

    /**
     * Agrega estado_visual, el status de la confirmación por WhatsApp, `grupo`
     * y `sena` a cada turno ya cargado con relacionesDeListado().
     */
    private function decorarListado(Collection $turnos): Collection
    {
        $turnos->each(function ($turno) {
            $turno->estado_visual = $this->calcularEstadoVisual($turno);
            // whatsapp_mensajes trae respuesta_api/message_id/numero — datos internos
            // que no deben llegar al frontend, así que solo exponemos el status derivado.
            $turno->confirmacion_whatsapp_status = optional($turno->whatsappMensajes->first())->status;
            $turno->makeHidden('whatsappMensajes');
        });
        $this->adjuntarGrupos($turnos);
        Turno::adjuntarSena($turnos);

        return $turnos;
    }

    private function adjuntarGrupos($turnos): void
    {
        $ids = $turnos->pluck('grupo_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }
        $grupos = TurnoGrupo::with('promo:id,nombre')->whereIn('id', $ids)->get()->keyBy('id');
        $tramos = Turno::whereIn('grupo_id', $ids)->with(['profesional:id,nombre', 'servicios'])->orderBy('id')->get()->groupBy('grupo_id');

        foreach ($turnos as $turno) {
            if ($turno->grupo_id === null) {
                continue;
            }
            $grupo = $grupos[$turno->grupo_id] ?? null;
            // Promo de la que nace el grupo; null si es una seleccion suelta
            // (o si la promo se borro: la FK es nullOnDelete).
            $promo = $grupo?->promo;
            $turno->setAttribute('grupo', [
                'id' => $turno->grupo_id,
                'modo' => $grupo?->modo,
                'promo' => $promo ? ['id' => $promo->id, 'nombre' => $promo->nombre] : null,
                'tramos' => $tramos[$turno->grupo_id]->map(fn (Turno $t) => [
                    'turno_id' => $t->id,
                    'profesional_id' => $t->profesional_id,
                    'profesional_nombre' => $t->profesional?->nombre,
                    'fecha_hora' => $t->fecha_hora->format('Y-m-d\TH:i:s'),
                    'duracion_total_minutos' => $t->duracion_total_minutos,
                    'estado' => $t->estado,
                    // Servicios de ese tramo: la tarjeta muestra tambien los pasos de
                    // otra profesional (o de otro dia), que no estan en el listado.
                    'servicios' => $t->servicios->map(fn ($s) => ['id' => $s->id, 'nombre' => $s->nombre])->values()->all(),
                ])->all(),
            ]);
        }
    }

    // ─────────────────────────────────────────────
    // Helper — agrega "en_curso" como excepción liviana sobre
    // el estado real. "completado" se persiste (cron o manual),
    // nunca se calcula al vuelo.
    // ─────────────────────────────────────────────
    private function calcularEstadoVisual(Turno $turno): string
    {
        if ($turno->estado !== 'confirmado') {
            return $turno->estado; // completado, cancelado, etc. — ya persistido
        }

        $ahora = Carbon::now();
        $inicio = Carbon::parse($turno->fecha_hora);
        $fin = $inicio->copy()->addMinutes($turno->duracion_total_minutos);

        if ($inicio->lt($ahora) && $fin->gt($ahora)) {
            return 'en_curso';
        }

        return 'confirmado';
    }
}

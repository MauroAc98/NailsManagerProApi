<?php

namespace App\Services\Reservas;

use App\Models\Profesional;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reprogramacion de un grupo por la DUENA (combo-multi-profesional, PR 6d).
 * Todo o nada: lockea a todas las profesionales de los tramos vigentes
 * (SlotLock::conLocks, orden ascendente) y valida cada tramo con AgendaChecks
 * ignorando los turnos del propio grupo. Sin regla de slots (solo flujos de
 * clienta). Solo se mueven los tramos NO cancelados; el ancla es el primero que
 * queda (menor id) y el resto conserva su desfasaje respecto de ella. No
 * re-precia, no toca PagoSena y NO avisa por WhatsApp: devuelve los turnos
 * movidos (ancla primero) para que el aviso (PR 6f) los tome de ahi.
 */
class ReprogramarGrupoService
{
    public function __construct(private SlotLock $lock, private AgendaChecks $checks)
    {
    }

    /** @return Collection<int, Turno> turnos movidos, el ancla primero */
    public function reprogramar(User $user, int $grupoId, Carbon $nuevoInicio): Collection
    {
        $tramos = Turno::delUsuario($user)->where('grupo_id', $grupoId)->where('estado', '!=', 'cancelado')->orderBy('id')->get();
        if ($tramos->isEmpty()) {
            throw new ReprogramacionException('No queda ningún turno de la promo para reprogramar.', 'grupo_sin_pendientes');
        }
        if ($tramos->contains(fn (Turno $t) => $t->estado === 'completado')) {
            throw new ReprogramacionException('No se puede reprogramar la promo: alguno de sus turnos ya se atendió.', 'grupo_en_curso');
        }

        $ancla = Carbon::parse($tramos->first()->getRawOriginal('fecha_hora'));
        $propios = $tramos->pluck('id')->all();
        $nombres = Profesional::whereIn('id', $tramos->pluck('profesional_id'))->pluck('nombre', 'id');

        return $this->lock->conLocks($tramos->pluck('profesional_id')->all(), function () use ($tramos, $ancla, $nuevoInicio, $propios, $nombres) {
            $nuevos = [];
            foreach ($tramos as $t) {
                $offset = intdiv(Carbon::parse($t->getRawOriginal('fecha_hora'))->getTimestamp() - $ancla->getTimestamp(), 60);
                $desde = $nuevoInicio->copy()->addMinutes($offset);
                $problema = $this->checks->problemaDeTramo($t->profesional_id, $nombres[$t->profesional_id] ?? '', $desde, (int) $t->duracion_total_minutos, $propios);
                if ($problema) {
                    throw new ReprogramacionException($problema['message'], $problema['code'] ?? null);
                }
                $nuevos[$t->id] = $desde->format('Y-m-d H:i:s');
            }

            foreach ($tramos as $t) {
                $t->update(['fecha_hora' => $nuevos[$t->id]]);
            }
            // El recordatorio ya enviado/gestionado era para el horario viejo.
            WhatsappMensaje::whereIn('turno_id', $propios)->where('tipo', 'recordatorio')->delete();

            return $tramos->each->refresh();
        });
    }
}

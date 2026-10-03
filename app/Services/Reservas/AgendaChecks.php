<?php

namespace App\Services\Reservas;

use App\Models\ReservaWeb;
use App\Models\SlotDisponible;
use App\Models\Turno;
use Illuminate\Support\Carbon;

/**
 * Chequeos de agenda del flujo de la DUENA (alta/edicion manual): rango de
 * atencion, choque con turnos confirmados y holds vivos de reserva online.
 * Se aplican por profesional y por tramo. NO incluye la regla de slots activos:
 * esa solo rige para flujos de clienta (online), no para la duena.
 */
class AgendaChecks
{
    public function horarioAtencion(int $profesionalId, Carbon $fechaHora): ?string
    {
        $slots = SlotDisponible::where('profesional_id', $profesionalId)->activos()->orderBy('hora')->get();

        if ($slots->isEmpty()) {
            return 'No tenés horarios de atención configurados. Configurálos en Ajustes.';
        }

        $horaMin = Carbon::parse($slots->first()->hora)->format('H:i:s');
        $horaMax = Carbon::parse($slots->last()->hora)->format('H:i:s');
        $horaTurno = $fechaHora->format('H:i:s');

        if ($horaTurno < $horaMin || $horaTurno > $horaMax) {
            $minFmt = Carbon::parse($horaMin)->format('H:i');
            $maxFmt = Carbon::parse($horaMax)->format('H:i');

            return "El horario de atención es de {$minFmt} a {$maxFmt}hs.";
        }

        return null;
    }

    public function choque(
        int $profesionalId,
        string $fechaHora,
        int $duracion,
        int|array|null $excluirId = null,
    ): ?Turno {
        $inicio = Carbon::parse($fechaHora);
        $fin = $inicio->copy()->addMinutes($duracion);
        $fecha = $inicio->toDateString();

        $query = Turno::where('profesional_id', $profesionalId)
            ->confirmados()
            ->delaFecha($fecha)
            ->with(['cliente', 'servicios'])
            ->solapaCon($inicio, $fin);

        if ($excluirId) {
            $query->whereNotIn('id', (array) $excluirId);
        }

        return $query->first();
    }

    /**
     * Primer problema de UN tramo de la duena (rango de atencion, choque con
     * otro turno, hold online vivo) como cuerpo de un 422 `{message, code?}`,
     * o null si la profesional esta libre. `$excluirTurnoIds` = turnos propios
     * que no cuentan como choque (reprogramar un grupo).
     *
     * @param  int[]  $excluirTurnoIds
     * @return array{message: string, code?: string}|null
     */
    public function problemaDeTramo(int $profesionalId, string $nombre, Carbon $desde, int $duracion, array $excluirTurnoIds = []): ?array
    {
        if ($error = $this->horarioAtencion($profesionalId, $desde)) {
            return ['message' => "{$nombre}: {$error}"];
        }
        $inicio = $desde->format('Y-m-d H:i:s');
        if ($this->choque($profesionalId, $inicio, $duracion, $excluirTurnoIds)) {
            return ['message' => "{$nombre} ya tiene un turno que se pisa con este horario. Elegí otro horario."];
        }
        if ($this->holdVivo($profesionalId, $inicio, $duracion)) {
            return [
                'message' => 'Una clienta está reservando ese horario en este momento. Probá en unos minutos o elegí otro horario.',
                'code' => 'slot_held',
            ];
        }

        return null;
    }

    public function holdVivo(int $profesionalId, string $fechaHora, int $duracion): bool
    {
        $inicio = Carbon::parse($fechaHora);
        $fin = $inicio->copy()->addMinutes($duracion);

        return ReservaWeb::where('profesional_id', $profesionalId)
            ->vivos(Carbon::now()->timestamp)
            ->whereDate('fecha', $inicio->toDateString())
            ->get()
            ->contains(function (ReservaWeb $r) use ($inicio, $fin) {
                $desde = Carbon::parse(substr((string) $r->getRawOriginal('fecha'), 0, 10) . ' ' . $r->getRawOriginal('slot_hora'));
                $hasta = $desde->copy()->addMinutes((int) $r->duracion_total_minutos);

                return $desde->lt($fin) && $hasta->gt($inicio);
            });
    }
}

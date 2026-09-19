<?php

namespace App\Services\Reservas;

use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calcula los horarios libres de un salon para una fecha y un conjunto de
 * servicios, por profesional.
 *
 * "Ahora", anticipacion minima y ventana de holds llegan por parametro para
 * poder testear sin depender del reloj.
 */
class DisponibilidadService
{
    /**
     * @param  Collection<int, \App\Models\Servicio>  $servicios  servicios ya validados (activos, del salon)
     * @param  Profesional|null  $profesional  null = "cualquiera": une por hora
     * @return array<int, array{hora: string, profesional_ids: array<int,int>}>
     */
    public function calcular(
        User $user,
        string $fecha,
        Collection $servicios,
        ?Profesional $profesional,
        Carbon $ahora,
        int $anticipacionMinutos,
        int $ventanaPagoMinutos,
    ): array {
        $duracion = (int) $servicios->sum('duracion_minutos');
        $servicioIds = $servicios->pluck('id')->all();

        $profesionales = $this->profesionalesCandidatas($user, $profesional, $servicioIds);
        if ($profesionales->isEmpty()) {
            return [];
        }

        $minimo = $ahora->copy()->addMinutes($anticipacionMinutos);
        $holds = $this->holdsVigentes($user, $fecha, $ahora, $ventanaPagoMinutos);
        $ocupacion = $this->turnosDelDia($user, $fecha, $profesionales->pluck('id')->all());
        $slotsPorProfesional = $this->slotsPorProfesional($user, $profesionales);

        $paso = max(1, (int) config('reservas.paso_minutos', 30));
        $porHora = [];

        foreach ($profesionales as $prof) {
            foreach ($this->grilla($slotsPorProfesional[$prof->id] ?? [], $paso) as $hora) {
                $inicio = Carbon::parse("{$fecha} {$hora}");
                $fin = $inicio->copy()->addMinutes($duracion);

                if ($inicio->lt($minimo)) {
                    continue;
                }
                if ($this->solapaAlguno($holds, $inicio, $fin)) {
                    continue;
                }
                if ($this->solapaAlguno($ocupacion[$prof->id] ?? [], $inicio, $fin)) {
                    continue;
                }

                $porHora[$hora][] = $prof->id;
            }
        }

        ksort($porHora);

        $resultado = [];
        foreach ($porHora as $hora => $ids) {
            $resultado[] = ['hora' => (string) $hora, 'profesional_ids' => array_values(array_unique($ids))];
        }

        return $resultado;
    }

    /**
     * Los slots configurados definen el RANGO de atencion de la profesional
     * (minimo a maximo); un turno puede empezar en cualquier momento dentro
     * de el. Genera los inicios candidatos desde el minimo hasta el maximo
     * inclusive, cada $paso minutos (alineados al minimo).
     *
     * Solo se exige que el INICIO caiga en el rango, no que el fin entre antes
     * del maximo: es la misma regla que TurnoController::validarHorarioAtencion.
     *
     * @param  array<int, string>  $horas  'HH:MM' de los slots activos
     * @return array<int, string>
     */
    private function grilla(array $horas, int $paso): array
    {
        if ($horas === []) {
            return [];
        }

        $minutos = array_map(fn (string $h) => ((int) substr($h, 0, 2)) * 60 + (int) substr($h, 3, 2), $horas);
        $desde = min($minutos);
        $hasta = max($minutos);

        $grilla = [];
        for ($m = $desde; $m <= $hasta; $m += $paso) {
            $grilla[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }

        return $grilla;
    }

    /**
     * Profesionales activas del salon que ofrecen TODOS los servicios pedidos.
     * Con $profesional dado, solo esa (si ofrece todo).
     *
     * @return Collection<int, Profesional>
     */
    private function profesionalesCandidatas(User $user, ?Profesional $profesional, array $servicioIds): Collection
    {
        $query = Profesional::where('user_id', $user->id)->where('activo', true)->orderBy('id');
        if ($profesional) {
            $query->where('id', $profesional->id);
        }

        return $query->with('servicios:id')->get()->filter(function (Profesional $p) use ($servicioIds) {
            $ofrecidos = $p->servicios->pluck('id')->all();

            return count(array_diff($servicioIds, $ofrecidos)) === 0;
        })->values();
    }

    /**
     * Slots activos por profesional. Los slots legacy (profesional_id NULL)
     * se asignan a la profesional por defecto del salon: la activa mas antigua.
     * Si esa profesional no es candidata, esos slots quedan fuera.
     *
     * @return array<int, array<int, string>> profesional_id => ['HH:MM', ...]
     */
    private function slotsPorProfesional(User $user, Collection $candidatas): array
    {
        $porDefecto = Profesional::where('user_id', $user->id)->where('activo', true)->oldest('id')->value('id');
        $ids = $candidatas->pluck('id')->all();

        $resultado = [];
        $slots = SlotDisponible::where('user_id', $user->id)->activos()->orderBy('hora')->get();

        foreach ($slots as $slot) {
            $profId = $slot->profesional_id ?? $porDefecto;
            if (! in_array($profId, $ids, true)) {
                continue;
            }
            $resultado[$profId][] = substr((string) $slot->hora, 0, 5);
        }

        foreach ($resultado as $profId => $horas) {
            $resultado[$profId] = array_values(array_unique($horas));
        }

        return $resultado;
    }

    /**
     * Turnos confirmados que tocan el dia, por profesional, como intervalos
     * [inicio, fin). Se leen los valores crudos y se parsean en la zona de la
     * app para no depender del cast datetime de Eloquent.
     *
     * @return array<int, array<int, array{0: Carbon, 1: Carbon}>>
     */
    private function turnosDelDia(User $user, string $fecha, array $profesionalIds): array
    {
        $inicioDia = Carbon::parse("{$fecha} 00:00:00");
        $finDia = $inicioDia->copy()->addDay();

        $turnos = Turno::where('user_id', $user->id)
            ->whereIn('profesional_id', $profesionalIds)
            ->confirmados()
            ->solapaCon($inicioDia, $finDia)
            ->get();

        $resultado = [];
        foreach ($turnos as $turno) {
            $inicio = Carbon::parse($turno->getRawOriginal('fecha_hora'));
            $resultado[$turno->profesional_id][] = [
                $inicio,
                $inicio->copy()->addMinutes((int) $turno->duracion_total_minutos),
            ];
        }

        return $resultado;
    }

    /**
     * Reservas web pending_payment creadas dentro de la ventana de pago. No
     * tienen profesional_id, asi que bloquean el horario para todas.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function holdsVigentes(User $user, string $fecha, Carbon $ahora, int $ventanaMinutos): array
    {
        $reservas = ReservaWeb::where('user_id', $user->id)
            ->pendientes()
            ->whereDate('fecha', $fecha)
            ->where('created_at', '>=', $ahora->copy()->subMinutes($ventanaMinutos)->format('Y-m-d H:i:s'))
            ->get();

        $holds = [];
        foreach ($reservas as $reserva) {
            $inicio = Carbon::parse($fecha . ' ' . $reserva->getRawOriginal('slot_hora'));
            $holds[] = [$inicio, $inicio->copy()->addMinutes((int) $reserva->duracion_total_minutos)];
        }

        return $holds;
    }

    /** Solapamiento semi-abierto: los intervalos adyacentes no se pisan. */
    private function solapaAlguno(array $intervalos, Carbon $inicio, Carbon $fin): bool
    {
        foreach ($intervalos as [$desde, $hasta]) {
            if ($desde->lt($fin) && $hasta->gt($inicio)) {
                return true;
            }
        }

        return false;
    }
}

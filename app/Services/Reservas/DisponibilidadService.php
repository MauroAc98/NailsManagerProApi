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
        $servicioIds = $servicios->pluck('id')->all();

        $profesionales = $this->profesionalesCandidatas($user, $profesional, $servicioIds);
        if ($profesionales->isEmpty()) {
            return [];
        }

        $holds = $this->holdsVigentes($user, $fecha, $fecha, $ahora, $ventanaPagoMinutos);
        $ocupacion = $this->turnosDelRango($user, $fecha, $fecha, $profesionales->pluck('id')->all());
        $slotsPorProfesional = $this->slotsPorProfesional($user, $profesionales);

        return $this->calcularDia(
            $fecha,
            (int) $servicios->sum('duracion_minutos'),
            $profesionales,
            $slotsPorProfesional,
            $holds[$fecha] ?? [],
            $ocupacion,
            $ahora->copy()->addMinutes($anticipacionMinutos),
        );
    }

    /**
     * Cuenta los inicios libres de cada dia del rango [$desde, $hasta]
     * (Y-m-d, inclusive) y devuelve solo los dias con al menos uno. Carga
     * profesionales, slots, turnos y holds UNA vez para todo el rango y
     * calcula cada dia en memoria: mismo resultado que llamar a calcular()
     * dia por dia, sin N consultas por dia.
     *
     * @param  Collection<int, \App\Models\Servicio>  $servicios
     * @return array<string, int> fecha => cantidad de inicios libres
     */
    public function contarLibresPorDia(
        User $user,
        string $desde,
        string $hasta,
        Collection $servicios,
        ?Profesional $profesional,
        Carbon $ahora,
        int $anticipacionMinutos,
        int $ventanaPagoMinutos,
    ): array {
        $profesionales = $this->profesionalesCandidatas($user, $profesional, $servicios->pluck('id')->all());
        if ($profesionales->isEmpty()) {
            return [];
        }

        $duracion = (int) $servicios->sum('duracion_minutos');
        $minimo = $ahora->copy()->addMinutes($anticipacionMinutos);
        $holds = $this->holdsVigentes($user, $desde, $hasta, $ahora, $ventanaPagoMinutos);
        $ocupacion = $this->turnosDelRango($user, $desde, $hasta, $profesionales->pluck('id')->all());
        $slotsPorProfesional = $this->slotsPorProfesional($user, $profesionales);

        $resultado = [];
        $dia = Carbon::parse($desde)->startOfDay();
        $ultimo = Carbon::parse($hasta)->startOfDay();
        while ($dia->lte($ultimo)) {
            $fecha = $dia->format('Y-m-d');
            $libres = $this->calcularDia($fecha, $duracion, $profesionales, $slotsPorProfesional, $holds[$fecha] ?? [], $ocupacion, $minimo);
            if ($libres !== []) {
                $resultado[$fecha] = count($libres);
            }
            $dia->addDay();
        }

        return $resultado;
    }

    /**
     * @param  Collection<int, Profesional>  $profesionales
     * @return array<int, array{hora: string, profesional_ids: array<int,int>}>
     */
    private function calcularDia(
        string $fecha,
        int $duracion,
        Collection $profesionales,
        array $slotsPorProfesional,
        array $holds,
        array $ocupacion,
        Carbon $minimo,
    ): array {
        // Candidatos = EXACTAMENTE los slots activos configurados de cada profesional
        // (sin grilla ni horarios intermedios). Se ofrece un inicio si la duracion
        // total entra sin pisar turnos de ESA profesional, holds ni el lead time.
        $porHora = [];

        foreach ($profesionales as $prof) {
            foreach ($slotsPorProfesional[$prof->id] ?? [] as $hora) {
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
     * Turnos confirmados que tocan el rango de dias, por profesional, como intervalos
     * [inicio, fin). Se leen los valores crudos y se parsean en la zona de la
     * app para no depender del cast datetime de Eloquent.
     *
     * @return array<int, array<int, array{0: Carbon, 1: Carbon}>>
     */
    private function turnosDelRango(User $user, string $desde, string $hasta, array $profesionalIds): array
    {
        $inicioDia = Carbon::parse("{$desde} 00:00:00");
        $finDia = Carbon::parse("{$hasta} 00:00:00")->addDay();

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
     * Reservas web pending_payment creadas dentro de la ventana de pago,
     * agrupadas por fecha. No tienen profesional_id, asi que bloquean el
     * horario para todas.
     *
     * TODO(reserva-online): reservas_web aun no tiene profesional_id; cuando lo
     * tenga (slice posterior) el hold debe bloquear solo a su profesional.
     *
     * @return array<string, array<int, array{0: Carbon, 1: Carbon}>> fecha => intervalos
     */
    private function holdsVigentes(User $user, string $desde, string $hasta, Carbon $ahora, int $ventanaMinutos): array
    {
        $reservas = ReservaWeb::where('user_id', $user->id)
            ->pendientes()
            ->whereDate('fecha', '>=', $desde)
            ->whereDate('fecha', '<=', $hasta)
            ->where('created_at', '>=', $ahora->copy()->subMinutes($ventanaMinutos)->format('Y-m-d H:i:s'))
            ->get();

        $holds = [];
        foreach ($reservas as $reserva) {
            $fecha = substr((string) $reserva->getRawOriginal('fecha'), 0, 10);
            $inicio = Carbon::parse($fecha . ' ' . $reserva->getRawOriginal('slot_hora'));
            $holds[$fecha][] = [$inicio, $inicio->copy()->addMinutes((int) $reserva->duracion_total_minutos)];
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

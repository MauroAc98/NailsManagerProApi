<?php

namespace App\Services\Reservas;

use App\Models\BloqueoAgenda;
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
 * "Ahora" y anticipacion minima llegan por parametro para poder testear sin
 * depender del reloj. Un hold (reservas_web held/pending_payment) bloquea solo
 * a SU profesional mientras expira_en (epoch) sea futuro; el estado nunca se
 * confia solo (el job de expiracion solo ordena).
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
    ): array {
        $servicioIds = $servicios->pluck('id')->all();

        $profesionales = $this->profesionalesCandidatas($user, $profesional, $servicioIds);
        if ($profesionales->isEmpty()) {
            return [];
        }

        $holds = $this->holdsVigentes($user, $fecha, $fecha, $ahora);
        $ocupacion = $this->turnosDelRango($user, $fecha, $fecha, $profesionales->pluck('id')->all());
        $slotsPorProfesional = $this->slotsPorProfesional($user, $profesionales);
        $bloqueos = $this->bloqueosDelRango($user, $fecha, $fecha);

        return $this->calcularDia(
            $fecha,
            (int) $servicios->sum('duracion_minutos'),
            $profesionales,
            $slotsPorProfesional,
            $holds[$fecha] ?? [],
            $ocupacion,
            $ahora->copy()->addMinutes($anticipacionMinutos),
            $bloqueos[$fecha] ?? null,
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
    ): array {
        $profesionales = $this->profesionalesCandidatas($user, $profesional, $servicios->pluck('id')->all());
        if ($profesionales->isEmpty()) {
            return [];
        }

        $duracion = (int) $servicios->sum('duracion_minutos');
        $minimo = $ahora->copy()->addMinutes($anticipacionMinutos);
        $holds = $this->holdsVigentes($user, $desde, $hasta, $ahora);
        $ocupacion = $this->turnosDelRango($user, $desde, $hasta, $profesionales->pluck('id')->all());
        $slotsPorProfesional = $this->slotsPorProfesional($user, $profesionales);
        $bloqueos = $this->bloqueosDelRango($user, $desde, $hasta);

        $resultado = [];
        $dia = Carbon::parse($desde)->startOfDay();
        $ultimo = Carbon::parse($hasta)->startOfDay();
        while ($dia->lte($ultimo)) {
            $fecha = $dia->format('Y-m-d');
            $libres = $this->calcularDia($fecha, $duracion, $profesionales, $slotsPorProfesional, $holds[$fecha] ?? [], $ocupacion, $minimo, $bloqueos[$fecha] ?? null);
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
        array $holds, // profesional_id => intervalos de holds vivos ese dia
        array $ocupacion,
        Carbon $minimo,
        ?array $bloqueos = null, // ['diaCompleto' => [profId|0 => true], 'parcial' => [profId|0 => intervalos]] de ESE dia
    ): array {
        // Candidatos = EXACTAMENTE los slots activos configurados de cada profesional
        // (sin grilla ni horarios intermedios). Se ofrece un inicio si la duracion
        // total entra sin pisar turnos ni holds vivos de ESA profesional, ni el lead time.
        $porHora = [];

        $diaDeLaSemana = Carbon::parse($fecha);
        $diaCompleto = $bloqueos['diaCompleto'] ?? [];
        $parcial = $bloqueos['parcial'] ?? [];

        foreach ($profesionales as $prof) {
            if (! $prof->atiendeEl($diaDeLaSemana)) {
                continue;
            }
            // 0 = bloqueo salon-wide (aplica a todas las profesionales).
            if (isset($diaCompleto[0]) || isset($diaCompleto[$prof->id])) {
                continue;
            }

            $bloqueosParciales = array_merge($parcial[0] ?? [], $parcial[$prof->id] ?? []);

            foreach ($slotsPorProfesional[$prof->id] ?? [] as $hora) {
                $inicio = Carbon::parse("{$fecha} {$hora}");
                $fin = $inicio->copy()->addMinutes($duracion);

                if ($inicio->lt($minimo)) {
                    continue;
                }
                if ($this->solapaAlguno($holds[$prof->id] ?? [], $inicio, $fin)) {
                    continue;
                }
                if ($this->solapaAlguno($ocupacion[$prof->id] ?? [], $inicio, $fin)) {
                    continue;
                }
                if ($this->solapaAlguno($bloqueosParciales, $inicio, $fin)) {
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
    public function profesionalesCandidatas(User $user, ?Profesional $profesional, array $servicioIds): Collection
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
     * Horas 'HH:MM' de los slots activos de UNA profesional (mismas reglas que
     * la disponibilidad: los slots legacy sin profesional van a la por defecto).
     *
     * @return array<int, string>
     */
    public function horasActivas(User $user, Profesional $profesional): array
    {
        return $this->slotsPorProfesional($user, collect([$profesional]))[$profesional->id] ?? [];
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
     * Holds vivos (held/pending_payment con expira_en futuro) del rango,
     * agrupados por fecha y por profesional_id. Filas sin profesional_id
     * (legacy) no bloquean a nadie.
     *
     * @return array<string, array<int, array<int, array{0: Carbon, 1: Carbon}>>> fecha => profesional_id => intervalos
     */
    private function holdsVigentes(User $user, string $desde, string $hasta, Carbon $ahora): array
    {
        $reservas = ReservaWeb::where('user_id', $user->id)
            ->vivos($ahora->timestamp)
            ->whereNotNull('profesional_id')
            ->whereDate('fecha', '>=', $desde)
            ->whereDate('fecha', '<=', $hasta)
            ->get();

        $holds = [];
        foreach ($reservas as $reserva) {
            [$fecha, $intervalo] = $this->intervaloDeHold($reserva);
            $holds[$fecha][$reserva->profesional_id][] = $intervalo;
        }

        return $holds;
    }

    /**
     * Bloqueos del rango, agrupados por fecha. Cada fecha trae dos listas
     * separadas por profesional_id (0 = salon-wide, aplica a todas):
     * 'diaCompleto' (ambos horarios null) marca profesionales/dia
     * completamente excluidos; 'parcial' (ambos horarios presentes) trae
     * los intervalos a mergear con holds/turnos en solapaAlguno().
     *
     * @return array<string, array{diaCompleto: array<int,bool>, parcial: array<int, array<int, array{0: Carbon, 1: Carbon}>>}>
     */
    private function bloqueosDelRango(User $user, string $desde, string $hasta): array
    {
        $bloqueos = BloqueoAgenda::delUsuario($user)
            ->whereDate('fecha', '>=', $desde)
            ->whereDate('fecha', '<=', $hasta)
            ->get();

        $resultado = [];
        foreach ($bloqueos as $bloqueo) {
            $fecha = $bloqueo->fecha->format('Y-m-d');
            $profId = $bloqueo->profesional_id ?? 0;

            if ($bloqueo->hora_desde === null || $bloqueo->hora_hasta === null) {
                $resultado[$fecha]['diaCompleto'][$profId] = true;
                continue;
            }

            $inicio = Carbon::parse("{$fecha} {$bloqueo->hora_desde}");
            $fin = Carbon::parse("{$fecha} {$bloqueo->hora_hasta}");
            $resultado[$fecha]['parcial'][$profId][] = [$inicio, $fin];
        }

        return $resultado;
    }

    /** @return array{0: string, 1: array{0: Carbon, 1: Carbon}} */
    private function intervaloDeHold(ReservaWeb $reserva): array
    {
        $fecha = substr((string) $reserva->getRawOriginal('fecha'), 0, 10);
        $inicio = Carbon::parse($fecha . ' ' . $reserva->getRawOriginal('slot_hora'));

        return [$fecha, [$inicio, $inicio->copy()->addMinutes((int) $reserva->duracion_total_minutos)]];
    }

    /**
     * Intervalos [inicio, fin) ocupados de UNA profesional un dia: turnos
     * confirmados + holds vivos. Sirve a PoliticaHold (alta ocupacion) y a los
     * chequeos de HoldService / ConfirmarReservaService.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}> ordenados por inicio
     */
    public function ocupacionDelDia(int $profesionalId, string $fecha, Carbon $ahora, ?int $ignorarReservaId = null): array
    {
        $inicioDia = Carbon::parse("{$fecha} 00:00:00");
        $finDia = $inicioDia->copy()->addDay();

        $intervalos = [];
        $turnos = Turno::where('profesional_id', $profesionalId)->confirmados()->solapaCon($inicioDia, $finDia)->get();
        foreach ($turnos as $turno) {
            $inicio = Carbon::parse($turno->getRawOriginal('fecha_hora'));
            $intervalos[] = [$inicio, $inicio->copy()->addMinutes((int) $turno->duracion_total_minutos)];
        }

        $query = ReservaWeb::where('profesional_id', $profesionalId)
            ->vivos($ahora->timestamp)
            ->whereDate('fecha', $fecha);
        if ($ignorarReservaId) {
            $query->where('id', '!=', $ignorarReservaId);
        }
        foreach ($query->get() as $reserva) {
            $intervalos[] = $this->intervaloDeHold($reserva)[1];
        }

        usort($intervalos, fn ($a, $b) => $a[0] <=> $b[0]);

        return $intervalos;
    }

    /**
     * Semantica semi-abierta: el intervalo [fecha hora, +duracion) no pisa
     * ningun turno confirmado ni hold vivo de la profesional (ignorando
     * $ignorarReservaId, p.ej. el propio hold) y, si $anticipacionMinutos no
     * es null, arranca a partir de ahora + anticipacion. NO valida que la hora
     * sea un slot activo: eso lo decide quien llama.
     */
    public function estaLibre(
        int $profesionalId,
        string $fecha,
        string $hora,
        int $duracion,
        Carbon $ahora,
        ?int $ignorarReservaId = null,
        ?int $anticipacionMinutos = null,
    ): bool {
        $inicio = Carbon::parse("{$fecha} {$hora}");
        $fin = $inicio->copy()->addMinutes($duracion);

        if ($anticipacionMinutos !== null && $inicio->lt($ahora->copy()->addMinutes($anticipacionMinutos))) {
            return false;
        }

        return ! $this->solapaAlguno($this->ocupacionDelDia($profesionalId, $fecha, $ahora, $ignorarReservaId), $inicio, $fin);
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

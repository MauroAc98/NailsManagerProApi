<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Servicio;
use App\Models\Turno;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class TurnoController extends Controller
{
    private const MAX_TURNOS_POR_CLIENTE = 2;

    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = Turno::delUsuario($user)->with(['cliente', 'servicios', 'reservaWeb']);

        if ($request->filled('fecha')) {
            $query->delaFecha($request->fecha);
        } elseif ($request->filled('desde') && $request->filled('hasta')) {
            $query->delRango($request->desde, $request->hasta);
        } elseif ($request->filled('mes')) {
            $query->whereRaw("TO_CHAR(fecha_hora, 'YYYY-MM') = ?", [$request->mes]);
        }

        if ($request->filled('buscar')) {
            $buscar = $request->buscar;
            $query->whereHas(
                'cliente',
                fn($q) => $q->where('nombre_completo', 'LIKE', "%{$buscar}%")
            );
        }

        return response()->json($query->orderBy('fecha_hora')->get());
    }

    public function marcas(Request $request): JsonResponse
    {
        $request->validate(['mes' => 'required|date_format:Y-m']);

        $user   = $request->user();
        $turnos = Turno::delUsuario($user)
            ->confirmados()
            ->whereRaw("TO_CHAR(fecha_hora, 'YYYY-MM') = ?", [$request->mes])
            ->get(['fecha_hora']);

        $marcas = $turnos->groupBy(fn($t) => Carbon::parse($t->fecha_hora)->toDateString())
            ->map(fn($grupo) => [
                'cantidad' => $grupo->count(),
                'dots'     => [['color' => 'pink']],
            ]);

        return response()->json($marcas);
    }

    public function disponibilidad(Request $request): JsonResponse
    {
        $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
        ]);

        $user     = $request->user();
        $slots    = $user->slotsDisponibles()->activos()->orderBy('hora')->get();
        $turnos   = Turno::delUsuario($user)
            ->confirmados()
            ->delRango($request->desde, $request->hasta)
            ->get(['fecha_hora', 'duracion_total_minutos']);

        $reservas = collect();

        $manana = Carbon::today()->toDateString();
        $inicio = max($request->desde, $manana);

        if ($inicio > $request->hasta) {
            return response()->json([]);
        }

        $periodo = new \DatePeriod(
            new \DateTime($inicio),
            new \DateInterval('P1D'),
            (new \DateTime($request->hasta))->modify('+1 day'),
        );

        $resultado = [];

        foreach ($periodo as $dia) {
            $fecha      = $dia->format('Y-m-d');
            $ahora      = Carbon::now();
            $esHoy      = $fecha === $ahora->toDateString();
            $horaActual = $ahora->hour;

            $turnosDia   = $turnos->filter(fn($t) => Carbon::parse($t->fecha_hora)->toDateString() === $fecha);
            $reservasDia = $reservas->filter(fn($r) => $r->fecha->toDateString() === $fecha);

            $slotsDelDia = $slots->map(function ($slot) use ($turnosDia, $reservasDia, $esHoy, $horaActual) {
                $slotMinutos = (int) Carbon::parse($slot->hora)->format('H') * 60
                    + (int) Carbon::parse($slot->hora)->format('i');

                if ($esHoy && (int) Carbon::parse($slot->hora)->format('H') <= $horaActual) {
                    return ['hora' => Carbon::parse($slot->hora)->format('H') . 'hs', 'libre' => false];
                }

                foreach ($turnosDia as $turno) {
                    $inicioTurno = Carbon::parse($turno->fecha_hora)->hour * 60 + Carbon::parse($turno->fecha_hora)->minute;
                    $finTurno    = $inicioTurno + $turno->duracion_total_minutos;
                    if ($slotMinutos >= $inicioTurno && $slotMinutos < $finTurno) {
                        return ['hora' => Carbon::parse($slot->hora)->format('H') . 'hs', 'libre' => false];
                    }
                }

                foreach ($reservasDia as $reserva) {
                    $inicioReserva = Carbon::parse($reserva->slot_hora)->hour * 60 + Carbon::parse($reserva->slot_hora)->minute;
                    $finReserva    = $inicioReserva + $reserva->duracion_total_minutos;
                    if ($slotMinutos >= $inicioReserva && $slotMinutos < $finReserva) {
                        return ['hora' => Carbon::parse($slot->hora)->format('H') . 'hs', 'libre' => false];
                    }
                }

                return ['hora' => Carbon::parse($slot->hora)->format('H') . 'hs', 'libre' => true];
            });

            $resultado[] = ['fecha' => $fecha, 'slots' => $slotsDelDia->values()];
        }

        return response()->json($resultado);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cliente_id'     => 'required|integer|exists:clientes,id',
            'servicio_ids'   => 'required|array|min:1',
            'servicio_ids.*' => 'integer|exists:servicios,id',
            'fecha_hora'     => 'required|date|after_or_equal:today',
            'notas'          => 'nullable|string',
        ]);

        $user      = $request->user();
        $cliente   = $user->clientes()->findOrFail($data['cliente_id']);
        $servicios = Servicio::whereIn('id', $data['servicio_ids'])->get();
        $fechaHora = Carbon::parse($data['fecha_hora']);

        // ── Regla 0: la hora debe estar dentro del horario de atención ──
        $errorHorario = $this->validarHorarioAtencion($user, $fechaHora);
        if ($errorHorario) {
            return response()->json(['message' => $errorHorario], 422);
        }

        // ── Regla 1: máximo de turnos por clienta por día ────────
        $turnosClienta = Turno::delUsuario($user)
            ->where('cliente_id', $cliente->id)
            ->delaFecha($fechaHora->toDateString())
            ->confirmados()
            ->count();

        if ($turnosClienta >= self::MAX_TURNOS_POR_CLIENTE) {
            return response()->json([
                'message' => 'La clienta ya tiene el máximo de ' . self::MAX_TURNOS_POR_CLIENTE . ' turnos para este día.',
            ], 422);
        }

        // ── Regla 2: no repetir servicio el mismo día ────────────
        $serviciosYaAgendados = Turno::delUsuario($user)
            ->where('cliente_id', $cliente->id)
            ->delaFecha($fechaHora->toDateString())
            ->confirmados()
            ->with('servicios')
            ->get()
            ->flatMap(fn($t) => $t->servicios->pluck('id'));

        $repetidos = collect($data['servicio_ids'])->intersect($serviciosYaAgendados);

        if ($repetidos->isNotEmpty()) {
            $nombresRepetidos = $servicios->whereIn('id', $repetidos)->pluck('nombre')->join(', ');
            return response()->json([
                'message' => "La clienta ya tiene agendado: {$nombresRepetidos} para este día.",
            ], 422);
        }

        // ── Regla 3: choque de horario ───────────────────────────
        $duracionTotal = $servicios->sum('duracion_minutos');
        $turnoChocado  = $this->verificarChoque($user->id, $data['fecha_hora'], $duracionTotal);

        if ($turnoChocado) {
            $nombreCliente  = $turnoChocado->cliente?->nombre_completo ?? 'otra clienta';
            $serviciosChoque = $turnoChocado->servicios->pluck('nombre')->join(' + ');
            $horaChoque     = Carbon::parse($turnoChocado->fecha_hora)->format('H:i');
            $finChoque      = Carbon::parse($turnoChocado->fecha_hora)
                ->addMinutes($turnoChocado->duracion_total_minutos)
                ->format('H:i');

            return response()->json([
                'message' => "Las {$fechaHora->format('H:i')} cae dentro del turno de {$nombreCliente} ({$serviciosChoque}, {$horaChoque} - {$finChoque}). Elegí otro horario.",
            ], 422);
        }

        // ── Crear turno ──────────────────────────────────────────
        $turno = Turno::create([
            'user_id'                => $user->id,
            'cliente_id'             => $cliente->id,
            'reserva_web_id'         => null,
            'fecha_hora'             => $data['fecha_hora'],
            'duracion_total_minutos' => $duracionTotal,
            'estado'                 => 'confirmado',
            'origen'                 => 'app',
            'notas'                  => $data['notas'] ?? null,
        ]);

        $turno->servicios()->attach($data['servicio_ids']);

        return response()->json($turno->load(['cliente', 'servicios']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'cliente_id'     => 'required|integer|exists:clientes,id',
            'servicio_ids'   => 'required|array|min:1',
            'servicio_ids.*' => 'integer|exists:servicios,id',
            'fecha_hora'     => 'required|date|after_or_equal:today',
            'notas'          => 'nullable|string',
        ]);

        $user      = $request->user();
        $turno     = Turno::delUsuario($user)->findOrFail($id);
        $servicios = Servicio::whereIn('id', $data['servicio_ids'])->get();
        $fechaHora = Carbon::parse($data['fecha_hora']);

        // ── Regla -1: no se puede editar un turno que ya pasó ────
        if (Carbon::parse($turno->fecha_hora)->isPast()) {
            return response()->json([
                'message' => 'No se pueden modificar turnos que ya pasaron.',
            ], 422);
        }

        // ── Regla 0: la nueva hora debe estar dentro del horario de atención ──
        $errorHorario = $this->validarHorarioAtencion($user, $fechaHora);
        if ($errorHorario) {
            return response()->json(['message' => $errorHorario], 422);
        }

        // ── Regla: no repetir servicio el mismo día ──────────────
        $serviciosYaAgendados = Turno::delUsuario($user)
            ->where('cliente_id', $data['cliente_id'])
            ->delaFecha($fechaHora->toDateString())
            ->confirmados()
            ->where('id', '!=', $id)
            ->with('servicios')
            ->get()
            ->flatMap(fn($t) => $t->servicios->pluck('id'));

        $repetidos = collect($data['servicio_ids'])->intersect($serviciosYaAgendados);

        if ($repetidos->isNotEmpty()) {
            $nombresRepetidos = $servicios->whereIn('id', $repetidos)->pluck('nombre')->join(', ');
            return response()->json([
                'message' => "La clienta ya tiene agendado: {$nombresRepetidos} para este día.",
            ], 422);
        }

        // ── Choque de horario ────────────────────────────────────
        $duracionTotal = $servicios->sum('duracion_minutos');
        $turnoChocado  = $this->verificarChoque($user->id, $data['fecha_hora'], $duracionTotal, $id);

        if ($turnoChocado) {
            $nombreCliente  = $turnoChocado->cliente?->nombre_completo ?? 'otra clienta';
            $serviciosChoque = $turnoChocado->servicios->pluck('nombre')->join(' + ');
            $horaChoque     = Carbon::parse($turnoChocado->fecha_hora)->format('H:i');
            $finChoque      = Carbon::parse($turnoChocado->fecha_hora)
                ->addMinutes($turnoChocado->duracion_total_minutos)
                ->format('H:i');

            return response()->json([
                'message' => "Las {$fechaHora->format('H:i')} cae dentro del turno de {$nombreCliente} ({$serviciosChoque}, {$horaChoque} - {$finChoque}). Elegí otro horario.",
            ], 422);
        }

        $turno->update([
            'cliente_id'             => $data['cliente_id'],
            'fecha_hora'             => $data['fecha_hora'],
            'duracion_total_minutos' => $duracionTotal,
            'notas'                  => $data['notas'] ?? null,
        ]);

        $turno->servicios()->sync($data['servicio_ids']);

        return response()->json($turno->load(['cliente', 'servicios']));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $turno = Turno::delUsuario($user)->findOrFail($id);

        // No se puede cancelar un turno que ya pasó
        if (Carbon::parse($turno->fecha_hora)->isPast()) {
            return response()->json([
                'message' => 'No se pueden cancelar turnos que ya pasaron.',
            ], 422);
        }

        $turno->delete();

        return response()->json(['message' => 'Turno cancelado correctamente.']);
    }

    // ─────────────────────────────────────────────
    // Helper — valida que la hora del turno esté dentro
    // del rango definido por los slots activos del usuario.
    // Devuelve null si es válido, o un mensaje de error.
    // ─────────────────────────────────────────────
    private function validarHorarioAtencion(\App\Models\User $user, Carbon $fechaHora): ?string
    {
        $slots = $user->slotsDisponibles()->activos()->orderBy('hora')->get();

        if ($slots->isEmpty()) {
            return 'No tenés horarios de atención configurados. Configurálos en Ajustes.';
        }

        $horaMin   = Carbon::parse($slots->first()->hora)->format('H:i:s');
        $horaMax   = Carbon::parse($slots->last()->hora)->format('H:i:s');
        $horaTurno = $fechaHora->format('H:i:s');

        if ($horaTurno < $horaMin || $horaTurno > $horaMax) {
            $minFmt = Carbon::parse($horaMin)->format('H:i');
            $maxFmt = Carbon::parse($horaMax)->format('H:i');
            return "El horario de atención es de {$minFmt} a {$maxFmt}hs.";
        }

        return null;
    }

    // ─────────────────────────────────────────────
    // Helper — devuelve el turno que choca (con relaciones)
    // o null si no hay conflicto
    // ─────────────────────────────────────────────
    private function verificarChoque(
        int $userId,
        string $fechaHora,
        int $duracion,
        ?int $excluirId = null,
    ): ?Turno {
        $inicio = Carbon::parse($fechaHora);
        $fin    = $inicio->copy()->addMinutes($duracion);
        $fecha  = $inicio->toDateString();

        $query = Turno::where('user_id', $userId)
            ->confirmados()
            ->delaFecha($fecha)
            ->with(['cliente', 'servicios'])
            ->where(function ($q) use ($inicio, $fin) {
                $q->whereBetween('fecha_hora', [$inicio, $fin->subMinute()])
                    ->orWhereRaw("fecha_hora + (duracion_total_minutos || ' minutes')::interval > ?", [$inicio]);
            });

        if ($excluirId) {
            $query->where('id', '!=', $excluirId);
        }

        return $query->first();
    }
}
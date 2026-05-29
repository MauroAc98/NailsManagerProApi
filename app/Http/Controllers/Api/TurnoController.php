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
    // ─────────────────────────────────────────────
    // GET /api/turnos
    // Soporta: ?fecha=  ?desde=&hasta=  ?mes=  ?buscar=
    // ─────────────────────────────────────────────
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
                fn($q) =>
                $q->where('nombre_completo', 'LIKE', "%{$buscar}%")
            );
        }

        $turnos = $query->orderBy('fecha_hora')->get();

        return response()->json($turnos);
    }

    // ─────────────────────────────────────────────
    // GET /api/turnos/marcas?mes=2026-06
    // Para el calendario — devuelve fechas con cantidad de turnos
    // ─────────────────────────────────────────────
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

    // ─────────────────────────────────────────────
    // GET /api/turnos/disponibilidad?desde=&hasta=
    // Para generar la imagen de disponibilidad
    // ─────────────────────────────────────────────
    public function disponibilidad(Request $request): JsonResponse
    {
        $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
        ]);

        $user = $request->user();

        $slots = $user->slotsDisponibles()->activos()->orderBy('hora')->get();

        $turnos = Turno::delUsuario($user)
            ->confirmados()
            ->delRango($request->desde, $request->hasta)
            ->get(['fecha_hora', 'duracion_total_minutos']);

        $reservas = $user->reservasWeb()
            ->pendientes()
            ->whereBetween('fecha', [$request->desde, $request->hasta])
            ->get(['fecha', 'slot_hora', 'duracion_total_minutos']);

        $periodo  = new \DatePeriod(
            new \DateTime($request->desde),
            new \DateInterval('P1D'),
            (new \DateTime($request->hasta))->modify('+1 day'),
        );

        $resultado = [];

        foreach ($periodo as $dia) {
            $fecha        = $dia->format('Y-m-d');
            $ahora        = Carbon::now();
            $esHoy        = $fecha === $ahora->toDateString();
            $horaActual   = $ahora->hour;

            $turnosDia    = $turnos->filter(
                fn($t) =>
                Carbon::parse($t->fecha_hora)->toDateString() === $fecha
            );

            $reservasDia  = $reservas->filter(fn($r) => $r->fecha->toDateString() === $fecha);

            $slotsDelDia  = $slots->map(function ($slot) use ($turnosDia, $reservasDia, $esHoy, $horaActual) {
                $slotMinutos = (int) Carbon::parse($slot->hora)->format('H') * 60
                    + (int) Carbon::parse($slot->hora)->format('i');

                // Bloqueo por hora pasada
                if ($esHoy && (int) Carbon::parse($slot->hora)->format('H') <= $horaActual) {
                    return ['hora' => Carbon::parse($slot->hora)->format('H') . 'hs', 'libre' => false];
                }

                // Bloqueo por turno confirmado
                foreach ($turnosDia as $turno) {
                    $inicioTurno = Carbon::parse($turno->fecha_hora)->hour * 60
                        + Carbon::parse($turno->fecha_hora)->minute;
                    $finTurno    = $inicioTurno + $turno->duracion_total_minutos;

                    if ($slotMinutos >= $inicioTurno && $slotMinutos < $finTurno) {
                        return ['hora' => Carbon::parse($slot->hora)->format('H') . 'hs', 'libre' => false];
                    }
                }

                // Bloqueo por reserva pendiente
                foreach ($reservasDia as $reserva) {
                    $inicioReserva = Carbon::parse($reserva->slot_hora)->hour * 60
                        + Carbon::parse($reserva->slot_hora)->minute;
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

    // ─────────────────────────────────────────────
    // POST /api/turnos
    // ─────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cliente_id'   => 'required|integer|exists:clientes,id',
            'servicio_ids' => 'required|array|min:1',
            'servicio_ids.*' => 'integer|exists:servicios,id',
            'fecha_hora'   => 'required|date|after_or_equal:today',
            'notas'        => 'nullable|string',
        ]);

        $user = $request->user();

        // Verificar que el cliente pertenece a esta profesional
        $cliente = $user->clientes()->findOrFail($data['cliente_id']);

        // Calcular duración total
        $servicios      = Servicio::whereIn('id', $data['servicio_ids'])->get();
        $duracionTotal  = $servicios->sum('duracion_minutos');

        // Validar max turnos por cliente por día
        $fechaHora    = Carbon::parse($data['fecha_hora']);
        $turnosClienta = Turno::delUsuario($user)
            ->where('cliente_id', $cliente->id)
            ->delaFecha($fechaHora->toDateString())
            ->confirmados()
            ->count();

        if ($turnosClienta >= $user->max_turnos_por_cliente) {
            return response()->json([
                'message' => "La clienta ya tiene el máximo de {$user->max_turnos_por_cliente} turnos para este día.",
            ], 422);
        }

        // Verificar choque de horario
        $choque = $this->verificarChoque($user->id, $data['fecha_hora'], $duracionTotal);
        if ($choque) {
            return response()->json([
                'message' => "El horario de las {$fechaHora->format('H:i')} ya está ocupado.",
            ], 422);
        }

        // Crear turno
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

    // ─────────────────────────────────────────────
    // PUT /api/turnos/{id}
    // ─────────────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'cliente_id'     => 'required|integer|exists:clientes,id',
            'servicio_ids'   => 'required|array|min:1',
            'servicio_ids.*' => 'integer|exists:servicios,id',
            'fecha_hora'     => 'required|date|after_or_equal:today',
            'notas'          => 'nullable|string',
        ]);

        $user  = $request->user();
        $turno = Turno::delUsuario($user)->findOrFail($id);

        $servicios     = Servicio::whereIn('id', $data['servicio_ids'])->get();
        $duracionTotal = $servicios->sum('duracion_minutos');

        // Verificar choque excluyendo el turno actual
        $choque = $this->verificarChoque($user->id, $data['fecha_hora'], $duracionTotal, $id);
        if ($choque) {
            $hora = Carbon::parse($data['fecha_hora'])->format('H:i');
            return response()->json([
                'message' => "El horario de las {$hora} ya está ocupado.",
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

    // ─────────────────────────────────────────────
    // DELETE /api/turnos/{id}
    // ─────────────────────────────────────────────
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $turno = Turno::delUsuario($user)->findOrFail($id);

        $turno->delete();

        return response()->json(['message' => 'Turno cancelado correctamente.']);
    }

    // ─────────────────────────────────────────────
    // Helper privado — detecta choques de horario
    // ─────────────────────────────────────────────
    private function verificarChoque(
        int $userId,
        string $fechaHora,
        int $duracion,
        ?int $excluirId = null,
    ): bool {
        $inicio = Carbon::parse($fechaHora);
        $fin    = $inicio->copy()->addMinutes($duracion);
        $fecha  = $inicio->toDateString();

        $query = Turno::where('user_id', $userId)
            ->confirmados()
            ->delaFecha($fecha)
            ->where(function ($q) use ($inicio, $fin) {
                $q->whereBetween('fecha_hora', [$inicio, $fin->subMinute()])
                    // PostgreSQL ✓
                    ->orWhereRaw("fecha_hora + (duracion_total_minutos || ' minutes')::interval > ?", [$inicio]);
            });

        if ($excluirId) {
            $query->where('id', '!=', $excluirId);
        }

        return $query->exists();
    }
}

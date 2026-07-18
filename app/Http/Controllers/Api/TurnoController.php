<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class TurnoController extends Controller
{
    private const MAX_TURNOS_POR_CLIENTE = 2;

    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = Turno::delUsuario($user)
            ->where('estado', '!=', 'cancelado') // ocultos por defecto — no aparecen en la agenda
            ->with(['cliente', 'servicios', 'reservaWeb']);

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
                fn($q) => $q->where(function ($sub) use ($buscar) {
                    $sub->where('nombre', 'ILIKE', "%{$buscar}%")
                        ->orWhere('apellido', 'ILIKE', "%{$buscar}%");
                })
            );
        }

        if ($request->filled('servicio_id')) {
            $query->whereHas(
                'servicios',
                fn($q) => $q->where('servicios.id', $request->servicio_id)
            );
        }

        $turnos = $query->orderBy('fecha_hora')->get()->map(function ($turno) {
            $turno->estado_visual = $this->calcularEstadoVisual($turno);
            return $turno;
        });

        return response()->json($turnos);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $turno = Turno::delUsuario($user)
            ->with(['cliente', 'servicios'])
            ->findOrFail($id);

        $turno->estado_visual = $this->calcularEstadoVisual($turno);

        return response()->json($turno);
    }

    public function marcas(Request $request): JsonResponse
    {
        $request->validate([
            'mes'            => 'required|date_format:Y-m',
            'profesional_id' => 'sometimes|nullable|integer|exists:profesionales,id',
        ]);

        $user   = $request->user();
        $turnos = Turno::delUsuario($user)
            ->whereIn('estado', ['confirmado', 'completado']) // todo menos cancelado
            ->whereRaw("TO_CHAR(fecha_hora, 'YYYY-MM') = ?", [$request->mes])
            ->when(
                $request->filled('profesional_id'),
                fn ($q) => $q->where('profesional_id', $request->integer('profesional_id')),
            )
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
            'desde'          => 'required|date',
            'hasta'          => 'required|date|after_or_equal:desde',
            'profesional_id' => 'sometimes|nullable|integer|exists:profesionales,id',
        ]);

        $user = $request->user();

        $profesional = $this->resolverProfesional($user, $request->filled('profesional_id') ? (int) $request->profesional_id : null);

        if (!$profesional) {
            return response()->json([
                'message' => 'No hay profesionales configurados para esta cuenta.',
            ], 422);
        }

        $slots    = SlotDisponible::where('profesional_id', $profesional->id)->activos()->orderBy('hora')->get();
        $turnos   = Turno::where('profesional_id', $profesional->id)
            ->confirmados()
            ->delRango($request->desde, $request->hasta)
            ->get(['fecha_hora', 'duracion_total_minutos']);

        $reservas = collect();

        $manana = Carbon::today()->toDateString();
        $inicio = max($request->desde, $manana);

        // Si hoy ya pasó el último horario de atención, no tiene sentido
        // incluirlo en la imagen — excluimos hoy del periodo.
        if ($inicio === Carbon::today()->toDateString() && $slots->isNotEmpty()) {
            $ultimaHoraAtencion = Carbon::parse($slots->last()->hora)->hour;
            if (Carbon::now()->hour >= $ultimaHoraAtencion) {
                $inicio = Carbon::tomorrow()->toDateString();
            }
        }

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

                $horaFormateada = Carbon::parse($slot->hora)->minute > 0
                    ? Carbon::parse($slot->hora)->format('H:i') . 'hs'
                    : Carbon::parse($slot->hora)->format('H') . 'hs';

                if ($esHoy && (int) Carbon::parse($slot->hora)->format('H') <= $horaActual) {
                    return ['hora' => $horaFormateada, 'libre' => false];
                }

                foreach ($turnosDia as $turno) {
                    $inicioTurno = Carbon::parse($turno->fecha_hora)->hour * 60 + Carbon::parse($turno->fecha_hora)->minute;
                    $finTurno    = $inicioTurno + $turno->duracion_total_minutos;
                    if ($slotMinutos >= $inicioTurno && $slotMinutos < $finTurno) {
                        return ['hora' => $horaFormateada, 'libre' => false];
                    }
                }

                foreach ($reservasDia as $reserva) {
                    $inicioReserva = Carbon::parse($reserva->slot_hora)->hour * 60 + Carbon::parse($reserva->slot_hora)->minute;
                    $finReserva    = $inicioReserva + $reserva->duracion_total_minutos;
                    if ($slotMinutos >= $inicioReserva && $slotMinutos < $finReserva) {
                        return ['hora' => $horaFormateada, 'libre' => false];
                    }
                }

                return ['hora' => $horaFormateada, 'libre' => true];
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
            'profesional_id' => 'sometimes|nullable|integer|exists:profesionales,id',
        ]);

        $user      = $request->user();
        $cliente   = $user->clientes()->findOrFail($data['cliente_id']);
        $servicios = Servicio::whereIn('id', $data['servicio_ids'])->get();
        $fechaHora = Carbon::parse($data['fecha_hora']);

        // Backward compat: la app RN nunca manda profesional_id, así que
        // por defecto se resuelve al único Profesional de la cuenta (el que
        // existe siempre, ya sea por el backfill o por AuthController@register).
        $profesional = $this->resolverProfesional($user, $data['profesional_id'] ?? null);

        if (!$profesional) {
            return response()->json([
                'message' => 'No hay profesionales configurados para esta cuenta.',
            ], 422);
        }

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
        $turnoChocado  = $this->verificarChoque($profesional->id, $data['fecha_hora'], $duracionTotal);

        if ($turnoChocado) {
            $nombreCliente   = $turnoChocado->cliente
                ? trim("{$turnoChocado->cliente->nombre} {$turnoChocado->cliente->apellido}")
                : 'otra clienta';
            $serviciosChoque = $turnoChocado->servicios->pluck('nombre')->join(' + ');
            $horaChoque      = Carbon::parse($turnoChocado->fecha_hora)->format('H:i');
            $finChoque       = Carbon::parse($turnoChocado->fecha_hora)
                ->addMinutes($turnoChocado->duracion_total_minutos)
                ->format('H:i');

            return response()->json([
                'message' => "Las {$fechaHora->format('H:i')} cae dentro del turno de {$nombreCliente} ({$serviciosChoque}, {$horaChoque} - {$finChoque}). Elegí otro horario.",
            ], 422);
        }

        // ── Crear turno ──────────────────────────────────────────
        $turno = Turno::create([
            'user_id'                => $user->id,
            'profesional_id'         => $profesional->id,
            'cliente_id'             => $cliente->id,
            'reserva_web_id'         => null,
            'fecha_hora'             => $data['fecha_hora'],
            'duracion_total_minutos' => $duracionTotal,
            'estado'                 => 'confirmado',
            'origen'                 => 'app',
            'notas'                  => $data['notas'] ?? null,
        ]);

        $turno->servicios()->attach($data['servicio_ids']);

        // Disparar mensaje de confirmación por WhatsApp (en cola, no bloqueante)
        \App\Jobs\EnviarMensajeConfirmacion::dispatch($turno->id);

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
            'profesional_id' => 'sometimes|nullable|integer|exists:profesionales,id',
        ]);

        $user      = $request->user();
        $turno     = Turno::delUsuario($user)->findOrFail($id);
        $servicios = Servicio::whereIn('id', $data['servicio_ids'])->get();
        $fechaHora = Carbon::parse($data['fecha_hora']);

        // Backward compat: mismo default-resolution que en store().
        $profesional = $this->resolverProfesional($user, $data['profesional_id'] ?? null);

        if (!$profesional) {
            return response()->json([
                'message' => 'No hay profesionales configurados para esta cuenta.',
            ], 422);
        }

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
        $turnoChocado  = $this->verificarChoque($profesional->id, $data['fecha_hora'], $duracionTotal, $id);

        if ($turnoChocado) {
            $nombreCliente   = $turnoChocado->cliente
                ? trim("{$turnoChocado->cliente->nombre} {$turnoChocado->cliente->apellido}")
                : 'otra clienta';
            $serviciosChoque = $turnoChocado->servicios->pluck('nombre')->join(' + ');
            $horaChoque      = Carbon::parse($turnoChocado->fecha_hora)->format('H:i');
            $finChoque       = Carbon::parse($turnoChocado->fecha_hora)
                ->addMinutes($turnoChocado->duracion_total_minutos)
                ->format('H:i');

            return response()->json([
                'message' => "Las {$fechaHora->format('H:i')} cae dentro del turno de {$nombreCliente} ({$serviciosChoque}, {$horaChoque} - {$finChoque}). Elegí otro horario.",
            ], 422);
        }

        $cambioDeFecha = Carbon::parse($turno->fecha_hora)->toDateString() !== $fechaHora->toDateString();

        $turno->update([
            'cliente_id'             => $data['cliente_id'],
            'profesional_id'         => $profesional->id,
            'fecha_hora'             => $data['fecha_hora'],
            'duracion_total_minutos' => $duracionTotal,
            'notas'                  => $data['notas'] ?? null,
        ]);

        $turno->servicios()->sync($data['servicio_ids']);

        // Si el turno cambió de fecha, borrar el recordatorio ya enviado (si
        // existe) para que EnviarRecordatorios pueda volver a mandar uno para
        // la fecha nueva. El unique constraint (turno_id, tipo) en
        // whatsapp_mensajes bloquearía ese envío en silencio si no se limpia.
        if ($cambioDeFecha) {
            WhatsappMensaje::where('turno_id', $turno->id)
                ->where('tipo', 'recordatorio')
                ->delete();
        }

        return response()->json($turno->load(['cliente', 'servicios']));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $turno = Turno::delUsuario($user)->findOrFail($id);

        if (Carbon::parse($turno->fecha_hora)->isPast()) {
            return response()->json([
                'message' => 'No se pueden cancelar turnos que ya pasaron.',
            ], 422);
        }

        // Soft-cancel: se conserva el registro para historial/estadísticas.
        // index/marcas/disponibilidad/verificarChoque ya filtran por
        // confirmados(), así que un turno cancelado deja de aparecer
        // y de bloquear horarios automáticamente.
        $turno->update(['estado' => 'cancelado']);

        return response()->json(['message' => 'Turno cancelado correctamente.']);
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

        $ahora  = Carbon::now();
        $inicio = Carbon::parse($turno->fecha_hora);
        $fin    = $inicio->copy()->addMinutes($turno->duracion_total_minutos);

        if ($inicio->lt($ahora) && $fin->gt($ahora)) {
            return 'en_curso';
        }

        return 'confirmado';
    }

    // ─────────────────────────────────────────────
    // PATCH /api/turnos/{id}/completar
    // Permite a la profesional marcar un turno como finalizado
    // antes de que termine su duración estimada.
    // ─────────────────────────────────────────────
    public function completar(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $turno = Turno::delUsuario($user)->findOrFail($id);

        if ($turno->estado !== 'confirmado') {
            return response()->json([
                'message' => 'Este turno no se puede marcar como finalizado.',
            ], 422);
        }

        $turno->update(['estado' => 'completado']);

        return response()->json($turno->load(['cliente', 'servicios']));
    }

    // ─────────────────────────────────────────────
    // Helper — valida rango horario de atención
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
    // o null si no hay conflicto.
    // Algoritmo: hay solapamiento si inicio_existente < nuevo_fin
    //            AND fin_existente > nuevo_inicio
    // Se escopea por profesional_id (no por user_id): dos profesionales de
    // la misma cuenta pueden tener turnos válidos en el mismo horario, cada
    // uno con su propia agenda independiente.
    // ─────────────────────────────────────────────
    private function verificarChoque(
        int $profesionalId,
        string $fechaHora,
        int $duracion,
        ?int $excluirId = null,
    ): ?Turno {
        $inicio = Carbon::parse($fechaHora);
        $fin    = $inicio->copy()->addMinutes($duracion);
        $fecha  = $inicio->toDateString();

        $query = Turno::where('profesional_id', $profesionalId)
            ->confirmados()
            ->delaFecha($fecha)
            ->with(['cliente', 'servicios'])
            ->whereRaw(
                "fecha_hora < ? AND fecha_hora + (duracion_total_minutos || ' minutes')::interval > ?",
                [$fin, $inicio]
            );

        if ($excluirId) {
            $query->where('id', '!=', $excluirId);
        }

        return $query->first();
    }

    // ─────────────────────────────────────────────
    // Helper — resuelve el Profesional a usar para una request.
    // Si se manda profesional_id explícito, lo valida contra la cuenta
    // (404 si no existe o es de otro usuario). Si se omite, resuelve al
    // profesional default de la cuenta (el más antiguo) — este es el
    // mecanismo de backward-compat: la app RN nunca manda profesional_id.
    // ─────────────────────────────────────────────
    private function resolverProfesional(User $user, ?int $profesionalIdSolicitado): ?Profesional
    {
        return Profesional::resolverParaUsuario($user, $profesionalIdSolicitado);
    }
}
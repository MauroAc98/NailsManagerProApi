<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TurnoController extends Controller
{
    private const MAX_TURNOS_POR_CLIENTE = 2;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Turno::delUsuario($user)
            ->where('estado', '!=', 'cancelado') // ocultos por defecto — no aparecen en la agenda
            ->with([
                'cliente',
                'servicios',
                'reservaWeb',
                'whatsappMensajes' => fn ($q) => $q->where('tipo', 'confirmacion')->latest()->limit(1)->select(['id', 'turno_id', 'status']),
            ]);

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
                fn ($q) => $q->where(function ($sub) use ($buscar) {
                    $sub->where('nombre', 'ILIKE', "%{$buscar}%")
                        ->orWhere('apellido', 'ILIKE', "%{$buscar}%");
                })
            );
        }

        if ($request->filled('servicio_id')) {
            $query->whereHas(
                'servicios',
                fn ($q) => $q->where('servicios.id', $request->servicio_id)
            );
        }

        $turnos = $query->orderBy('fecha_hora')->get()->map(function ($turno) {
            $turno->estado_visual = $this->calcularEstadoVisual($turno);
            // whatsapp_mensajes trae respuesta_api/message_id/numero — datos internos
            // que no deben llegar al frontend, así que solo exponemos el status derivado.
            $turno->confirmacion_whatsapp_status = optional($turno->whatsappMensajes->first())->status;
            $turno->makeHidden('whatsappMensajes');

            return $turno;
        });

        return response()->json($turnos);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $turno = Turno::delUsuario($user)
            ->with([
                'cliente',
                'servicios',
                'whatsappMensajes' => fn ($q) => $q->where('tipo', 'confirmacion')->latest()->limit(1)->select(['id', 'turno_id', 'status']),
            ])
            ->findOrFail($id);

        $turno->estado_visual = $this->calcularEstadoVisual($turno);
        $turno->confirmacion_whatsapp_status = optional($turno->whatsappMensajes->first())->status;
        $turno->makeHidden('whatsappMensajes');

        return response()->json($turno);
    }

    public function marcas(Request $request): JsonResponse
    {
        $request->validate([
            'mes' => 'required|date_format:Y-m',
            'profesional_id' => 'sometimes|nullable|integer|exists:profesionales,id',
        ]);

        $user = $request->user();
        $turnos = Turno::delUsuario($user)
            ->whereIn('estado', ['confirmado', 'completado']) // todo menos cancelado
            ->whereRaw("TO_CHAR(fecha_hora, 'YYYY-MM') = ?", [$request->mes])
            ->when(
                $request->filled('profesional_id'),
                fn ($q) => $q->where('profesional_id', $request->integer('profesional_id')),
            )
            ->get(['fecha_hora']);

        $marcas = $turnos->groupBy(fn ($t) => Carbon::parse($t->fecha_hora)->toDateString())
            ->map(fn ($grupo) => [
                'cantidad' => $grupo->count(),
                'dots' => [['color' => 'pink']],
            ]);

        return response()->json($marcas);
    }

    public function disponibilidad(Request $request): JsonResponse
    {
        $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
            'profesional_id' => 'sometimes|nullable|integer|exists:profesionales,id',
        ]);

        $user = $request->user();

        $profesional = $this->resolverProfesional($user, $request->filled('profesional_id') ? (int) $request->profesional_id : null);

        if (! $profesional) {
            return response()->json([
                'message' => 'No hay profesionales configurados para esta cuenta.',
            ], 422);
        }

        $slots = SlotDisponible::where('profesional_id', $profesional->id)->activos()->orderBy('hora')->get();
        $turnos = Turno::where('profesional_id', $profesional->id)
            ->confirmados()
            ->delRango($request->desde, $request->hasta)
            ->get(['fecha_hora', 'duracion_total_minutos']);

        $reservas = collect();

        $manana = Carbon::today()->toDateString();
        $inicio = max($request->desde, $manana);

        // Si hoy ya pasó el último horario de atención, no tiene sentido
        // incluirlo en la imagen — excluimos hoy del periodo. Comparación
        // en minutos (no solo ->hour): con ->hour un slot de 19:30 se
        // consideraba "pasado" ya a las 19:00, excluyendo el día completo
        // mientras el salón seguía atendiendo.
        if ($inicio === Carbon::today()->toDateString() && $slots->isNotEmpty()) {
            $ultimoSlot = Carbon::parse($slots->last()->hora);
            $ultimaAtencionMinutos = $ultimoSlot->hour * 60 + $ultimoSlot->minute;
            $ahoraParaCorte = Carbon::now();
            $ahoraMinutosCorte = $ahoraParaCorte->hour * 60 + $ahoraParaCorte->minute;
            if ($ahoraMinutosCorte >= $ultimaAtencionMinutos) {
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
            $fecha = $dia->format('Y-m-d');
            $ahora = Carbon::now();
            $esHoy = $fecha === $ahora->toDateString();
            $horaActualMinutos = $ahora->hour * 60 + $ahora->minute;

            $turnosDia = $turnos->filter(fn ($t) => Carbon::parse($t->fecha_hora)->toDateString() === $fecha);
            $reservasDia = $reservas->filter(fn ($r) => $r->fecha->toDateString() === $fecha);

            $slotsDelDia = $slots->map(function ($slot) use ($turnosDia, $reservasDia, $esHoy, $horaActualMinutos) {
                $slotMinutos = (int) Carbon::parse($slot->hora)->format('H') * 60
                    + (int) Carbon::parse($slot->hora)->format('i');

                $horaFormateada = Carbon::parse($slot->hora)->minute > 0
                    ? Carbon::parse($slot->hora)->format('H:i').'hs'
                    : Carbon::parse($slot->hora)->format('H').'hs';

                // Comparación en minutos, no solo ->hour: un slot de 10:30
                // se marcaba pasado ya a las 10:00, perdiendo 30 min reales
                // de disponibilidad.
                if ($esHoy && $slotMinutos <= $horaActualMinutos) {
                    return ['hora' => $horaFormateada, 'libre' => false];
                }

                foreach ($turnosDia as $turno) {
                    $inicioTurno = Carbon::parse($turno->fecha_hora)->hour * 60 + Carbon::parse($turno->fecha_hora)->minute;
                    $finTurno = $inicioTurno + $turno->duracion_total_minutos;
                    if ($slotMinutos >= $inicioTurno && $slotMinutos < $finTurno) {
                        return ['hora' => $horaFormateada, 'libre' => false];
                    }
                }

                foreach ($reservasDia as $reserva) {
                    $inicioReserva = Carbon::parse($reserva->slot_hora)->hour * 60 + Carbon::parse($reserva->slot_hora)->minute;
                    $finReserva = $inicioReserva + $reserva->duracion_total_minutos;
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
            'cliente_id' => 'required|integer|exists:clientes,id',
            'servicio_ids' => 'required|array|min:1',
            'servicio_ids.*' => 'integer|exists:servicios,id',
            'fecha_hora' => 'required|date|after_or_equal:today',
            'notas' => 'nullable|string',
            'profesional_id' => 'sometimes|nullable|integer|exists:profesionales,id',
        ]);

        $user = $request->user();
        $cliente = $user->clientes()->findOrFail($data['cliente_id']);
        $servicios = Servicio::whereIn('id', $data['servicio_ids'])->get();
        $fechaHora = Carbon::parse($data['fecha_hora']);

        // Backward compat: la app RN nunca manda profesional_id, así que
        // por defecto se resuelve al único Profesional de la cuenta (el que
        // existe siempre, ya sea por el backfill o por AuthController@register).
        $profesional = $this->resolverProfesional($user, $data['profesional_id'] ?? null);

        if (! $profesional) {
            return response()->json([
                'message' => 'No hay profesionales configurados para esta cuenta.',
            ], 422);
        }

        // ── Regla -0.5: los servicios deben estar asignados a la profesional ──
        if ($servicios->pluck('id')->diff($profesional->servicios->pluck('id'))->isNotEmpty()) {
            return response()->json([
                'message' => 'Alguno de los servicios seleccionados no está asignado a esta profesional.',
            ], 422);
        }

        // ── Regla 0: la hora debe estar dentro del horario de atención ──
        $errorHorario = $this->validarHorarioAtencion($profesional->id, $fechaHora);
        if ($errorHorario) {
            return response()->json(['message' => $errorHorario], 422);
        }

        // ── Regla 1: máximo de turnos por cliente por día ────────
        $turnosCliente = Turno::delUsuario($user)
            ->where('cliente_id', $cliente->id)
            ->delaFecha($fechaHora->toDateString())
            ->confirmados()
            ->count();

        if ($turnosCliente >= self::MAX_TURNOS_POR_CLIENTE) {
            return response()->json([
                'message' => 'El cliente ya tiene el máximo de '.self::MAX_TURNOS_POR_CLIENTE.' turnos para este día.',
            ], 422);
        }

        // ── Regla 2: no repetir servicio el mismo día ────────────
        $serviciosYaAgendados = Turno::delUsuario($user)
            ->where('cliente_id', $cliente->id)
            ->delaFecha($fechaHora->toDateString())
            ->confirmados()
            ->with('servicios')
            ->get()
            ->flatMap(fn ($t) => $t->servicios->pluck('id'));

        $repetidos = collect($data['servicio_ids'])->intersect($serviciosYaAgendados);

        if ($repetidos->isNotEmpty()) {
            $nombresRepetidos = $servicios->whereIn('id', $repetidos)->pluck('nombre')->join(', ');

            return response()->json([
                'message' => "El cliente ya tiene agendado: {$nombresRepetidos} para este día.",
            ], 422);
        }

        // ── Regla 3: choque de horario ───────────────────────────
        $duracionTotal = $servicios->sum('duracion_minutos');
        $turnoChocado = $this->verificarChoque($profesional->id, $data['fecha_hora'], $duracionTotal);

        if ($turnoChocado) {
            $nombreCliente = $turnoChocado->cliente
                ? trim("{$turnoChocado->cliente->nombre} {$turnoChocado->cliente->apellido}")
                : 'otro cliente';
            $serviciosChoque = $turnoChocado->servicios->pluck('nombre')->join(' + ');
            $horaChoque = Carbon::parse($turnoChocado->fecha_hora)->format('H:i');
            $finChoque = Carbon::parse($turnoChocado->fecha_hora)
                ->addMinutes($turnoChocado->duracion_total_minutos)
                ->format('H:i');

            return response()->json([
                'message' => "Las {$fechaHora->format('H:i')} cae dentro del turno de {$nombreCliente} ({$serviciosChoque}, {$horaChoque} - {$finChoque}). Elegí otro horario.",
            ], 422);
        }

        // ── Crear turno ──────────────────────────────────────────
        $turno = Turno::create([
            'user_id' => $user->id,
            'profesional_id' => $profesional->id,
            'cliente_id' => $cliente->id,
            'reserva_web_id' => null,
            'fecha_hora' => $data['fecha_hora'],
            'duracion_total_minutos' => $duracionTotal,
            'estado' => 'confirmado',
            'origen' => 'app',
            'notas' => $data['notas'] ?? null,
        ]);

        $turno->servicios()->attach($data['servicio_ids']);

        // Disparar mensaje de confirmación por WhatsApp (en cola, no bloqueante)
        EnviarMensajeConfirmacion::dispatch($turno->id);

        return response()->json($turno->load(['cliente', 'servicios']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'cliente_id' => 'required|integer|exists:clientes,id',
            'servicio_ids' => 'required|array|min:1',
            'servicio_ids.*' => 'integer|exists:servicios,id',
            'fecha_hora' => 'required|date|after_or_equal:today',
            'notas' => 'nullable|string',
            'profesional_id' => 'sometimes|nullable|integer|exists:profesionales,id',
        ]);

        $user = $request->user();
        $turno = Turno::delUsuario($user)->findOrFail($id);
        $servicios = Servicio::whereIn('id', $data['servicio_ids'])->get();
        $fechaHora = Carbon::parse($data['fecha_hora']);

        // Backward compat: mismo default-resolution que en store().
        $profesional = $this->resolverProfesional($user, $data['profesional_id'] ?? null);

        if (! $profesional) {
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

        // ── Regla -0.5: los servicios deben estar asignados a la profesional ──
        if ($servicios->pluck('id')->diff($profesional->servicios->pluck('id'))->isNotEmpty()) {
            return response()->json([
                'message' => 'Alguno de los servicios seleccionados no está asignado a esta profesional.',
            ], 422);
        }

        // ── Regla 0: la nueva hora debe estar dentro del horario de atención ──
        $errorHorario = $this->validarHorarioAtencion($profesional->id, $fechaHora);
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
            ->flatMap(fn ($t) => $t->servicios->pluck('id'));

        $repetidos = collect($data['servicio_ids'])->intersect($serviciosYaAgendados);

        if ($repetidos->isNotEmpty()) {
            $nombresRepetidos = $servicios->whereIn('id', $repetidos)->pluck('nombre')->join(', ');

            return response()->json([
                'message' => "El cliente ya tiene agendado: {$nombresRepetidos} para este día.",
            ], 422);
        }

        // ── Choque de horario ────────────────────────────────────
        $duracionTotal = $servicios->sum('duracion_minutos');
        $turnoChocado = $this->verificarChoque($profesional->id, $data['fecha_hora'], $duracionTotal, $id);

        if ($turnoChocado) {
            $nombreCliente = $turnoChocado->cliente
                ? trim("{$turnoChocado->cliente->nombre} {$turnoChocado->cliente->apellido}")
                : 'otro cliente';
            $serviciosChoque = $turnoChocado->servicios->pluck('nombre')->join(' + ');
            $horaChoque = Carbon::parse($turnoChocado->fecha_hora)->format('H:i');
            $finChoque = Carbon::parse($turnoChocado->fecha_hora)
                ->addMinutes($turnoChocado->duracion_total_minutos)
                ->format('H:i');

            return response()->json([
                'message' => "Las {$fechaHora->format('H:i')} cae dentro del turno de {$nombreCliente} ({$serviciosChoque}, {$horaChoque} - {$finChoque}). Elegí otro horario.",
            ], 422);
        }

        $cambioDeFecha = Carbon::parse($turno->fecha_hora)->toDateString() !== $fechaHora->toDateString();

        $turno->update([
            'cliente_id' => $data['cliente_id'],
            'profesional_id' => $profesional->id,
            'fecha_hora' => $data['fecha_hora'],
            'duracion_total_minutos' => $duracionTotal,
            'notas' => $data['notas'] ?? null,
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
        $user = $request->user();
        $turno = Turno::delUsuario($user)->findOrFail($id);

        if (Carbon::parse($turno->fecha_hora)->isPast()) {
            return response()->json([
                'message' => 'No se pueden cancelar turnos que ya pasaron.',
            ], 422);
        }

        $data = $request->validate([
            'motivo_cancelacion' => 'required|string|max:255',
        ]);

        // Soft-cancel: se conserva el registro para historial/estadísticas.
        // index/marcas/disponibilidad/verificarChoque ya filtran por
        // confirmados(), así que un turno cancelado deja de aparecer
        // y de bloquear horarios automáticamente.
        $turno->update([
            'estado' => 'cancelado',
            'motivo_cancelacion' => $data['motivo_cancelacion'],
            'cancelado_en' => now(),
        ]);

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

        $ahora = Carbon::now();
        $inicio = Carbon::parse($turno->fecha_hora);
        $fin = $inicio->copy()->addMinutes($turno->duracion_total_minutos);

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
        $user = $request->user();
        $turno = Turno::delUsuario($user)->findOrFail($id);

        if ($turno->estado !== 'confirmado') {
            return response()->json([
                'message' => 'Este turno no se puede marcar como finalizado.',
            ], 422);
        }

        $data = $request->validate([
            'servicios' => 'sometimes|array|min:1',
            'servicios.*.servicio_id' => 'required_with:servicios|integer|exists:servicios,id',
            'servicios.*.precio' => 'required_with:servicios|numeric|min:0',
        ]);

        if (!empty($data['servicios'])) {
            $error = $this->validarServiciosDeLaCuenta($user, $data['servicios'])
                ?? $this->validarSinCambiosConcurrentes($turno, $data['servicios']);
            if ($error) {
                return $error;
            }
        }

        // Transacción: si el sync() de servicios/precios falla después del
        // update de estado, sin esto el turno queda "completado" con los
        // precios viejos (o sin precios), sin forma de saberlo desde afuera.
        DB::transaction(function () use ($turno, $data) {
            $turno->update(['estado' => 'completado']);

            if (!empty($data['servicios'])) {
                $turno->servicios()->sync(
                    collect($data['servicios'])->mapWithKeys(fn ($s) => [$s['servicio_id'] => ['precio' => $s['precio']]])
                );
            }
        });

        return response()->json($turno->load(['cliente', 'servicios']));
    }

    // ── Mismo criterio que la "Regla -0.5" de store()/update(): un
    // servicio_id que exista en la tabla pero pertenezca a otra cuenta no
    // puede terminar en el pivot de un turno propio. exists:servicios,id
    // por sí solo no alcanza a resolver esto (valida existencia global,
    // no tenencia). ──
    private function validarServiciosDeLaCuenta(User $user, array $servicios): ?JsonResponse
    {
        $servicioIds = collect($servicios)->pluck('servicio_id')->unique();
        $validos = Servicio::delUsuario($user)->whereIn('id', $servicioIds)->pluck('id');

        if ($servicioIds->diff($validos)->isNotEmpty()) {
            return response()->json([
                'message' => 'Alguno de los servicios seleccionados no pertenece a esta cuenta.',
            ], 422);
        }

        return null;
    }

    // ── sync() reemplaza el pivot completo con lo que mande el cliente. Si
    // el turno cambió de servicios (otra pestaña/dispositivo) entre que este
    // cliente lo cargó y confirmó el precio, un sync() a ciegas pisaría ese
    // cambio en silencio. En vez de eso, se rechaza y se pide reintentar con
    // datos frescos — para precios/plata, perder una actualización es peor
    // que pedirle a la profesional que reintente. ──
    private function validarSinCambiosConcurrentes(Turno $turno, array $servicios): ?JsonResponse
    {
        $enviados = collect($servicios)->pluck('servicio_id')->sort()->values()->all();
        $actuales = $turno->servicios()->pluck('servicios.id')->sort()->values()->all();

        if ($enviados !== $actuales) {
            return response()->json([
                'message' => 'Los servicios de este turno cambiaron mientras cargabas el precio. Volvé a intentar.',
            ], 409);
        }

        return null;
    }

    // ─────────────────────────────────────────────
    // PATCH /api/turnos/{id}/precios
    // Permite cargar/corregir los precios de un turno que ya está
    // completado — típicamente uno marcado automáticamente por el cron
    // CompletarTurnosVencidos, que queda "pendiente de cobro" (servicios
    // sin precio en el pivot) hasta que la profesional los carga acá.
    // ─────────────────────────────────────────────
    public function actualizarPrecios(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $turno = Turno::delUsuario($user)->findOrFail($id);

        if ($turno->estado !== 'completado') {
            return response()->json([
                'message' => 'Este turno no está completado.',
            ], 422);
        }

        $data = $request->validate([
            'servicios' => 'required|array|min:1',
            'servicios.*.servicio_id' => 'required|integer|exists:servicios,id',
            'servicios.*.precio' => 'required|numeric|min:0',
        ]);

        $error = $this->validarServiciosDeLaCuenta($user, $data['servicios'])
            ?? $this->validarSinCambiosConcurrentes($turno, $data['servicios']);
        if ($error) {
            return $error;
        }

        // sync() corre como delete+insert del pivot por debajo — sin
        // transacción, una falla a mitad de camino puede dejar el pivot
        // con menos servicios de los que tenía antes de intentar el cambio.
        DB::transaction(function () use ($turno, $data) {
            $turno->servicios()->sync(
                collect($data['servicios'])->mapWithKeys(fn ($s) => [$s['servicio_id'] => ['precio' => $s['precio']]])
            );
        });

        return response()->json($turno->load(['cliente', 'servicios']));
    }

    // Ventana de "pendientes de cobro" — sin esto la query trae TODOS los
    // turnos completados desde el origen de la cuenta, para siempre, y se
    // llama seguido (cada mount del layout + cada vez que la PWA vuelve a
    // primer plano). Un pendiente de cobro de más de 90 días es un caso que
    // ya debería resolverse a mano, no algo que la lista tenga que seguir
    // cargando indefinidamente.
    private const DIAS_VENTANA_PENDIENTES_DE_COBRO = 90;

    // ─────────────────────────────────────────────
    // GET /api/turnos/pendientes-de-cobro
    // Turnos completados (típicamente por el cron CompletarTurnosVencidos,
    // que no pide precio) que tienen al menos un servicio sin precio
    // cargado en el pivot. No hay estado/columna nueva: se detectan así.
    // ─────────────────────────────────────────────
    public function pendientesDeCobro(Request $request): JsonResponse
    {
        $user = $request->user();

        $turnos = Turno::delUsuario($user)
            ->where('estado', 'completado')
            ->where('fecha_hora', '>=', Carbon::now()->subDays(self::DIAS_VENTANA_PENDIENTES_DE_COBRO))
            ->orderBy('fecha_hora')
            ->with(['cliente', 'servicios'])
            ->get()
            ->filter(fn (Turno $t) => $t->servicios->contains(fn ($s) => is_null($s->pivot->precio)))
            ->values();

        return response()->json($turnos);
    }

    // ─────────────────────────────────────────────
    // GET /api/turnos/recordatorios-pendientes
    // Turnos confirmados de mañana, con cliente con teléfono, que todavía
    // no tienen un recordatorio gestionado (ni automático ni manual) — ver
    // marcarRecordatorioManual() y EnviarRecordatorios, que son las dos
    // formas en que un WhatsappMensaje tipo='recordatorio' puede existir.
    // ─────────────────────────────────────────────
    public function recordatoriosPendientes(Request $request): JsonResponse
    {
        $user = $request->user();
        $manana = Carbon::tomorrow()->toDateString();

        $turnos = Turno::delUsuario($user)
            ->confirmados()
            ->delaFecha($manana)
            ->whereDoesntHave('whatsappMensajes', fn ($q) => $q->where('tipo', 'recordatorio'))
            ->with(['cliente', 'servicios'])
            ->orderBy('fecha_hora')
            ->get()
            ->filter(fn (Turno $t) => ! empty($t->cliente?->telefono))
            ->values();

        return response()->json($turnos);
    }

    // ─────────────────────────────────────────────
    // GET /api/turnos/manana
    // Vista de REFERENCIA (sin acciones) de TODOS los turnos confirmados de
    // mañana, con cliente/servicios y el estado del recordatorio de cada
    // uno (null = todavía no se mandó). Complementa a
    // recordatoriosPendientes(), que es una lista de TAREAS (solo los que
    // faltan mandar) — esta es la lista completa, para "a quién atiendo
    // mañana" sin importar si el recordatorio ya salió o no.
    // ─────────────────────────────────────────────
    public function turnosManana(Request $request): JsonResponse
    {
        $user = $request->user();
        $manana = Carbon::tomorrow()->toDateString();

        $turnos = Turno::delUsuario($user)
            ->confirmados()
            ->delaFecha($manana)
            ->with([
                'cliente',
                'servicios',
                'whatsappMensajes' => fn ($q) => $q->where('tipo', 'recordatorio')->latest()->limit(1)->select(['id', 'turno_id', 'status']),
            ])
            ->orderBy('fecha_hora')
            ->get()
            ->map(function (Turno $t) {
                $t->recordatorio_status = optional($t->whatsappMensajes->first())->status;
                $t->makeHidden('whatsappMensajes');

                return $t;
            });

        return response()->json($turnos);
    }

    // ─────────────────────────────────────────────
    // GET /api/turnos/notificaciones
    // Panel de la campanita en Agenda: eventos reales de WhatsApp automático
    // de HOY (confirmaciones enviadas al agendar, recordatorios enviados a
    // la hora configurada para los turnos de mañana) más cuántos turnos
    // confirmados tiene mañana. `no_vistos` (para el badge) cuenta los
    // mensajes posteriores a users.notificaciones_vistas_at — ver
    // marcarNotificacionesVistas(). La lista `mensajes` siempre trae TODOS
    // los de hoy, vistos o no; el frontend no distingue visualmente entre
    // ellos, solo el número del badge cambia.
    // ─────────────────────────────────────────────
    public function notificaciones(Request $request): JsonResponse
    {
        $user = $request->user();
        $manana = Carbon::tomorrow()->toDateString();
        $vistasDesde = $user->notificaciones_vistas_at;

        $mensajes = WhatsappMensaje::where('user_id', $user->id)
            ->whereDate('created_at', now()->toDateString())
            ->with('turno.cliente')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $noVistos = $vistasDesde
            ? $mensajes->where('created_at', '>', $vistasDesde)->count()
            : $mensajes->count();

        $mensajes = $mensajes
            ->map(fn (WhatsappMensaje $m) => [
                'id' => $m->id,
                'tipo' => $m->tipo,
                'status' => $m->status,
                'cliente_nombre' => $m->turno?->cliente?->nombre,
                'cliente_apellido' => $m->turno?->cliente?->apellido,
                'created_at' => $m->created_at,
                // Texto real armado por WhatsappTemplate::mensajeLegible() al
                // momento del envío — se guarda tal cual en whatsapp_mensajes,
                // no hace falta reconstruirlo. Útil para diagnosticar un
                // fallo (ver exactamente qué se intentó mandar).
                'mensaje' => $m->mensaje,
            ])
            ->values();

        $turnosManana = Turno::delUsuario($user)->confirmados()->delaFecha($manana)->count();

        return response()->json([
            'turnos_manana' => $turnosManana,
            'no_vistos' => $noVistos,
            'mensajes' => $mensajes,
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/turnos/notificaciones/marcar-vistas
    // Se llama al abrir el panel de la campanita — todo lo que sea de HOY
    // en ese momento queda "visto"; un mensaje nuevo que llegue después
    // vuelve a sumar al badge.
    // ─────────────────────────────────────────────
    public function marcarNotificacionesVistas(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['notificaciones_vistas_at' => now()]);

        return response()->json(['ok' => true]);
    }

    // ─────────────────────────────────────────────
    // POST /api/turnos/{id}/recordatorio-manual
    // La profesional mandó el recordatorio a mano por wa.me (ver botón en
    // /agenda/recordatorios) — se registra igual que un envío automático
    // para que el turno deje de aparecer en recordatoriosPendientes().
    // Idempotente: un segundo llamado sobre el mismo turno no duplica el
    // registro (el botón puede tocarse más de una vez sin problema).
    // ─────────────────────────────────────────────
    public function marcarRecordatorioManual(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $turno = Turno::delUsuario($user)->with('cliente')->findOrFail($id);

        $yaExiste = WhatsappMensaje::where('turno_id', $turno->id)
            ->where('tipo', 'recordatorio')
            ->exists();

        if (! $yaExiste) {
            try {
                WhatsappMensaje::create([
                    'user_id' => $user->id,
                    'turno_id' => $turno->id,
                    'numero' => $turno->cliente?->telefono ?? '',
                    'mensaje' => '', // enviado manualmente por wa.me, no tenemos el texto final acá
                    'tipo' => 'recordatorio',
                    'message_id' => null,
                    'status' => 'manual',
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // exists()+create() no es atómico: si dos requests casi
                // simultáneos (doble tap) pasan el exists() antes de que el
                // primer create() confirme, el segundo choca acá contra el
                // unique constraint (turno_id, tipo) — ver migración
                // 2026_07_02_123237. Ya quedó creado por la otra request,
                // que es exactamente el resultado que queríamos, así que se
                // trata como éxito en vez de 500.
                if (! str_contains(strtolower($e->getMessage()), 'unique')) {
                    throw $e;
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    // ─────────────────────────────────────────────
    // Helper — valida rango horario de atención
    // ─────────────────────────────────────────────
    private function validarHorarioAtencion(int $profesionalId, Carbon $fechaHora): ?string
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
        $fin = $inicio->copy()->addMinutes($duracion);
        $fecha = $inicio->toDateString();

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

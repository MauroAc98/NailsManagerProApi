<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gasto;
use App\Models\Turno;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StatsController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/stats/dashboard
    // ─────────────────────────────────────────────
    public function dashboard(Request $request): JsonResponse
    {
        $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
            'profesional_id' => 'sometimes|nullable|integer|exists:profesionales,id',
        ]);

        $user = $request->user();

        $todosQuery = Turno::delUsuario($user)->delRango($request->desde, $request->hasta);

        if ($request->filled('profesional_id')) {
            $todosQuery->where('profesional_id', (int) $request->profesional_id);
        }

        $todos = $todosQuery->with('servicios')->get();

        // Las métricas de servicios/clientes se calculan sobre los turnos
        // "reales" (no cancelados) — un turno cancelado no representa ni un
        // servicio prestado ni una visita de la cliente.
        $turnosValidos = $todos->whereIn('estado', ['confirmado', 'completado']);
        $turnosCompletados = $todos->where('estado', 'completado');

        $ganancias = $turnosCompletados->flatMap(fn (Turno $t) => $t->servicios)->sum('pivot.precio');
        $gastos = $this->gastosDelRango($user, $request);

        return response()->json([
            'total_turnos' => $turnosValidos->count(),
            'turnos_por_estado' => [
                'completados' => $turnosCompletados->count(),
                'confirmados' => $todos->where('estado', 'confirmado')->count(),
                'cancelados' => $todos->where('estado', 'cancelado')->count(),
            ],
            'servicios_mas_pedidos' => $this->serviciosMasPedidos($turnosValidos),
            'clientes' => $this->clientesNuevasVsRecurrentes($user, $turnosValidos, $request->desde, $request->hasta),
            'ganancias' => $ganancias,
            'gastos' => $gastos,
            'ganancia_neta' => $ganancias - $gastos,
            'ganancias_por_servicio' => $this->gananciasPorServicio($turnosCompletados),
            'ganancias_por_dia' => $this->gananciasPorDia($turnosCompletados),
            'turnos_por_estado_por_dia_semana' => $this->turnosPorEstadoPorDiaSemana($todos),
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/stats/ocupacion
    // Heatmap hora × día de la semana: cuenta de turnos por combinación,
    // agregados sobre TODO el rango pedido (no un día calendario puntual) —
    // misma idea que turnosPorEstadoPorDiaSemana pero con la hora como
    // segunda dimensión. Solo turnos "reales" (confirmado/completado), mismo
    // criterio que $turnosValidos en dashboard(): un cancelado no ocupó la
    // agenda de verdad. Devuelve solo los buckets con al menos un turno (lista
    // rala) — el frontend rellena con 0 los que falten, mismo patrón que
    // ganancias_por_dia.
    // ─────────────────────────────────────────────
    public function ocupacion(Request $request): JsonResponse
    {
        $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
            'profesional_id' => 'sometimes|nullable|integer|exists:profesionales,id',
        ]);

        $user = $request->user();

        $query = Turno::delUsuario($user)
            ->delRango($request->desde, $request->hasta)
            ->whereIn('estado', ['confirmado', 'completado']);

        if ($request->filled('profesional_id')) {
            $query->where('profesional_id', (int) $request->profesional_id);
        }

        $turnos = $query->get(['fecha_hora']);

        // dia_semana en formato ISO (1 = lunes ... 7 = domingo), igual orden
        // que la semana del calendario del frontend (L M M J V S D).
        $porBucket = $turnos->groupBy(
            fn (Turno $t) => Carbon::parse($t->fecha_hora)->isoWeekday().'-'.Carbon::parse($t->fecha_hora)->hour
        );

        $resultado = $porBucket->map(function ($grupo, $clave) {
            [$diaSemana, $hora] = explode('-', $clave);

            return [
                'dia_semana' => (int) $diaSemana,
                'hora' => (int) $hora,
                'cantidad' => $grupo->count(),
            ];
        })->values();

        return response()->json($resultado);
    }

    // dia_semana en formato ISO (1 = lunes ... 7 = domingo). Siempre devuelve
    // las 7 entradas (incluso en 0) — a diferencia de ocupacion() de arriba,
    // acá conviene que el frontend no tenga que rellenar huecos para dibujar
    // un eje fijo de 7 barras.
    private function turnosPorEstadoPorDiaSemana($todos)
    {
        $porDia = $todos->groupBy(fn (Turno $t) => Carbon::parse($t->fecha_hora)->isoWeekday());

        $resultado = [];
        for ($dia = 1; $dia <= 7; $dia++) {
            $grupo = $porDia->get($dia, collect());
            $resultado[] = [
                'dia_semana' => $dia,
                'completados' => $grupo->where('estado', 'completado')->count(),
                'confirmados' => $grupo->where('estado', 'confirmado')->count(),
                'cancelados' => $grupo->where('estado', 'cancelado')->count(),
            ];
        }

        return $resultado;
    }

    // Consulta separada de la de turnos: los gastos no tienen turno asociado
    // (grano distinto), así que se agregan aparte en vez de un JOIN/UNION.
    // sum() de Laravel ya normaliza "sin filas" a 0 (nunca null).
    private function gastosDelRango($user, Request $request): float
    {
        $query = Gasto::delUsuario($user)->whereBetween('fecha', [$request->desde, $request->hasta]);

        if ($request->filled('profesional_id')) {
            $query->where('profesional_id', (int) $request->profesional_id);
        }

        return (float) $query->sum('monto');
    }

    // Tope defensivo de buckets — evita respuestas enormes si alguien manda
    // un rango desde/hasta absurdamente largo con granularidad semana/mes.
    private const MAX_BUCKETS_PERIODO = 120;

    // ─────────────────────────────────────────────
    // GET /api/stats/ganancias-por-periodo
    // Con desde/hasta explícitos (modo "Rango personalizado" del frontend):
    // la CONSULTA se recorta exacto a ese rango — nunca trae datos de fuera
    // de lo que el usuario eligió, aunque el rango no empiece justo en un
    // lunes (semana) o día 1 (mes). Como consecuencia, el primer y/o último
    // bucket pueden representar un período CALENDARIO incompleto (ej. medio
    // mes) — cada punto devuelve `completo: bool` para que el frontend lo
    // pueda comunicar en vez de mezclarlo en silencio con los completos.
    // Sin desde/hasta (modo "Mes" normal del navegador de arriba, que no
    // aplica acá): últimos 12 buckets terminando hoy, para dar un vistazo de
    // tendencia reciente sin importar qué mes calendario se esté navegando
    // en el resto de la pantalla — ahí sí quedan siempre alineados a bucket
    // completo, salvo el último (la semana/mes en curso, genuinamente sin
    // terminar todavía).
    // ─────────────────────────────────────────────
    public function gananciasPorPeriodo(Request $request): JsonResponse
    {
        $request->validate([
            'granularidad' => 'required|in:semana,mes',
            'profesional_id' => 'sometimes|nullable|integer|exists:profesionales,id',
            'desde' => 'sometimes|nullable|date',
            'hasta' => 'sometimes|nullable|date|after_or_equal:desde',
        ]);

        $user = $request->user();
        $granularidad = $request->granularidad;
        $tieneRangoExplicito = $request->filled('desde') && $request->filled('hasta');

        if ($tieneRangoExplicito) {
            $desdeLimite = Carbon::parse($request->desde)->startOfDay();
            $hastaLimite = Carbon::parse($request->hasta)->endOfDay();
        } else {
            $hastaLimite = Carbon::now();
            $desdeLimite = $granularidad === 'semana'
                ? $hastaLimite->copy()->subWeeks(11)->startOfWeek()
                : $hastaLimite->copy()->subMonths(11)->startOfMonth();
        }

        $query = Turno::delUsuario($user)
            ->where('estado', 'completado')
            ->where('fecha_hora', '>=', $desdeLimite)
            ->where('fecha_hora', '<=', $hastaLimite);

        if ($request->filled('profesional_id')) {
            $query->where('profesional_id', (int) $request->profesional_id);
        }

        $montosPorBucket = $query->with('servicios')->get()
            ->flatMap(fn (Turno $t) => $t->servicios->map(fn ($s) => [
                'fecha' => $t->fecha_hora,
                'precio' => $s->pivot->precio,
            ]))
            ->groupBy(fn ($item) => $granularidad === 'semana'
                ? Carbon::parse($item['fecha'])->startOfWeek()->format('Y-m-d')
                : Carbon::parse($item['fecha'])->startOfMonth()->format('Y-m-d'))
            ->map(fn ($grupo) => $grupo->sum('precio'));

        $inicioPrimerBucket = $granularidad === 'semana'
            ? $desdeLimite->copy()->startOfWeek()
            : $desdeLimite->copy()->startOfMonth();

        $puntos = [];
        $cursor = $inicioPrimerBucket->copy();
        while ($cursor <= $hastaLimite && count($puntos) < self::MAX_BUCKETS_PERIODO) {
            $finBucket = $granularidad === 'semana' ? $cursor->copy()->endOfWeek() : $cursor->copy()->endOfMonth();
            $completo = $cursor >= $desdeLimite && $finBucket <= $hastaLimite;

            $puntos[] = [
                'fecha' => $cursor->format('Y-m-d'),
                'monto' => $montosPorBucket[$cursor->format('Y-m-d')] ?? 0,
                'completo' => $completo,
            ];
            $cursor = $granularidad === 'semana' ? $cursor->addWeek() : $cursor->addMonth();
        }

        return response()->json(['puntos' => $puntos, 'truncado' => $cursor <= $hastaLimite]);
    }

    private function gananciasPorDia($turnosCompletados)
    {
        return $turnosCompletados
            ->groupBy(fn (Turno $t) => Carbon::parse($t->fecha_hora)->format('Y-m-d'))
            ->map(fn ($grupo, $fecha) => [
                'fecha' => $fecha,
                'monto' => $grupo->flatMap(fn (Turno $t) => $t->servicios)->sum('pivot.precio'),
            ])
            ->sortBy('fecha')
            ->values();
    }

    private function gananciasPorServicio($turnosCompletados)
    {
        return $turnosCompletados
            ->flatMap(fn (Turno $t) => $t->servicios)
            ->groupBy('id')
            ->map(fn ($grupo) => [
                'servicio_id' => $grupo->first()->id,
                'nombre' => $grupo->first()->nombre,
                'monto' => $grupo->sum('pivot.precio'),
            ])
            ->sortByDesc('monto')
            ->values();
    }

    private function serviciosMasPedidos($turnos)
    {
        return $turnos
            ->flatMap(fn (Turno $t) => $t->servicios)
            ->groupBy('id')
            ->map(fn ($grupo) => [
                'servicio_id' => $grupo->first()->id,
                'nombre' => $grupo->first()->nombre,
                'cantidad' => $grupo->count(),
            ])
            ->sortByDesc('cantidad')
            ->values();
    }

    // Una cliente es "nueva" si su turno más antiguo (con cualquier
    // profesional de la cuenta, no solo la filtrada acá) cae dentro del
    // período consultado — no es "nueva para esta profesional", es nueva
    // para el estudio. El resto de las clientes con turnos en el período
    // son "recurrentes".
    private function clientesNuevasVsRecurrentes($user, $turnos, string $desde, string $hasta): array
    {
        $clienteIds = $turnos->pluck('cliente_id')->unique()->filter()->values();

        if ($clienteIds->isEmpty()) {
            return ['nuevas' => 0, 'recurrentes' => 0];
        }

        $primerTurnoPorCliente = Turno::delUsuario($user)
            ->whereIn('cliente_id', $clienteIds)
            ->whereIn('estado', ['confirmado', 'completado'])
            ->selectRaw('cliente_id, MIN(fecha_hora) as primera_fecha')
            ->groupBy('cliente_id')
            ->pluck('primera_fecha', 'cliente_id');

        $rangoDesde = Carbon::parse($desde)->startOfDay();
        $rangoHasta = Carbon::parse($hasta)->endOfDay();

        $nuevas = 0;
        $recurrentes = 0;

        foreach ($clienteIds as $clienteId) {
            $primera = $primerTurnoPorCliente[$clienteId] ?? null;
            $esNueva = $primera && Carbon::parse($primera)->between($rangoDesde, $rangoHasta);

            $esNueva ? $nuevas++ : $recurrentes++;
        }

        return ['nuevas' => $nuevas, 'recurrentes' => $recurrentes];
    }
}

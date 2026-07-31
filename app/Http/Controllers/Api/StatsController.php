<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        return response()->json([
            'total_turnos' => $turnosValidos->count(),
            'turnos_por_estado' => [
                'completados' => $turnosCompletados->count(),
                'confirmados' => $todos->where('estado', 'confirmado')->count(),
                'cancelados' => $todos->where('estado', 'cancelado')->count(),
            ],
            'servicios_mas_pedidos' => $this->serviciosMasPedidos($turnosValidos),
            'clientes' => $this->clientesNuevasVsRecurrentes($user, $turnosValidos, $request->desde, $request->hasta),
            'ganancias' => $turnosCompletados->flatMap(fn (Turno $t) => $t->servicios)->sum('pivot.precio'),
            'ganancias_por_servicio' => $this->gananciasPorServicio($turnosCompletados),
            'ganancias_por_dia' => $this->gananciasPorDia($turnosCompletados),
        ]);
    }

    // Tope defensivo de buckets — evita respuestas enormes si alguien manda
    // un rango desde/hasta absurdamente largo con granularidad semana/mes.
    private const MAX_BUCKETS_PERIODO = 120;

    // ─────────────────────────────────────────────
    // GET /api/stats/ganancias-por-periodo
    // Con desde/hasta explícitos (modo "Rango personalizado" del frontend):
    // bucketiza EXACTAMENTE ese rango, sin acolcharlo a una cantidad fija —
    // así "Semana"/"Mes" respetan el mismo rango que el usuario eligió a
    // mano, en vez de ignorarlo. Sin desde/hasta (modo "Mes" normal del
    // navegador de arriba, que no aplica acá): últimos 12 buckets terminando
    // hoy, para dar un vistazo de tendencia reciente sin importar qué mes
    // calendario se esté navegando en el resto de la pantalla.
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
            $desdeSolicitado = Carbon::parse($request->desde)->startOfDay();
            $hastaLimite = Carbon::parse($request->hasta)->endOfDay();
        } else {
            $hastaLimite = Carbon::now();
            $desdeSolicitado = $granularidad === 'semana'
                ? $hastaLimite->copy()->subWeeks(11)->startOfWeek()
                : $hastaLimite->copy()->subMonths(11)->startOfMonth();
        }

        $inicioPrimerBucket = $granularidad === 'semana'
            ? $desdeSolicitado->copy()->startOfWeek()
            : $desdeSolicitado->copy()->startOfMonth();

        $query = Turno::delUsuario($user)
            ->where('estado', 'completado')
            ->where('fecha_hora', '>=', $inicioPrimerBucket)
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

        $puntos = [];
        $cursor = $inicioPrimerBucket->copy();
        while ($cursor <= $hastaLimite && count($puntos) < self::MAX_BUCKETS_PERIODO) {
            $fecha = $cursor->format('Y-m-d');
            $puntos[] = ['fecha' => $fecha, 'monto' => $montosPorBucket[$fecha] ?? 0];
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

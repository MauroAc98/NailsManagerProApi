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

        $turnosQuery = Turno::delUsuario($user)
            ->whereIn('estado', ['confirmado', 'completado'])
            ->delRango($request->desde, $request->hasta);

        if ($request->filled('profesional_id')) {
            $turnosQuery->where('profesional_id', (int) $request->profesional_id);
        }

        $turnos = $turnosQuery->with('servicios')->get();

        return response()->json([
            'total_turnos' => $turnos->count(),
            'servicios_mas_pedidos' => $this->serviciosMasPedidos($turnos),
            'clientes' => $this->clientesNuevasVsRecurrentes($user, $turnos, $request->desde, $request->hasta),
        ]);
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

    // Una clienta es "nueva" si su turno más antiguo (con cualquier
    // profesional de la cuenta, no solo la filtrada acá) cae dentro del
    // período consultado — no es "nueva para esta profesional", es nueva
    // para el estudio. El resto de las clientas con turnos en el período
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\DecoraListadoDeTurnos;
use App\Http\Controllers\Controller;
use App\Models\Turno;
use App\Services\Cobros\CobrosCalculator as Cobros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CobrosController extends Controller
{
    use DecoraListadoDeTurnos;

    private const POR_PAGINA = 30;

    private const MAX_POR_PAGINA = 100;

    // ─────────────────────────────────────────────
    // GET /api/cobros
    //
    // La pantalla Cobros paginada. Los turnos de la ventana desde–hasta se
    // cargan livianos para calcular estado de pago, conteos y totales sobre el
    // conjunto filtrado completo; solo la página pedida lleva el detalle
    // completo (misma forma que GET /turnos, más `cobro`).
    //
    // Parámetros (todos opcionales):
    //   page, per_page (máx 100), turno, pago, periodo, buscar,
    //   desde/hasta (default: 90 días atrás y 60 adelante de `hoy`),
    //   hoy (el día del cliente: el servidor no decide qué es "hoy").
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1',
            'turno' => 'sometimes|in:todos,confirmado,finalizado',
            'pago' => 'sometimes|in:todos,'.implode(',', Cobros::PAGOS),
            'periodo' => 'sometimes|in:todo,hoy,7dias,mes,proximos',
            'buscar' => 'sometimes|nullable|string|max:100',
            'desde' => 'sometimes|date_format:Y-m-d',
            'hasta' => 'sometimes|date_format:Y-m-d',
            'hoy' => 'sometimes|date_format:Y-m-d',
        ]);

        $hoy = $data['hoy'] ?? Carbon::now()->toDateString();
        $desde = $data['desde'] ?? Carbon::createFromFormat('Y-m-d', $hoy)->subDays(90)->toDateString();
        $hasta = $data['hasta'] ?? Carbon::createFromFormat('Y-m-d', $hoy)->addDays(60)->toDateString();
        $pagina = (int) ($data['page'] ?? 1);
        $porPagina = min((int) ($data['per_page'] ?? self::POR_PAGINA), self::MAX_POR_PAGINA);
        $user = $request->user();

        $turnos = Turno::delUsuario($user)
            ->where('estado', '!=', 'cancelado')
            ->delRango($desde, $hasta)
            ->with(['cliente', 'servicios', 'reservaWeb.pagoSena'])
            ->get();

        $filas = Cobros::filas($turnos);
        $sinFiltroDePago = Cobros::filtrar($filas, $data['turno'] ?? 'todos', $data['periodo'] ?? 'todo', $data['buscar'] ?? '', $hoy);
        $visibles = Cobros::ordenar(Cobros::soloPago($sinFiltroDePago, $data['pago'] ?? 'todos'));

        $delaPagina = $visibles->forPage($pagina, $porPagina)->values();

        return response()->json([
            'data' => $this->detalle($user, $delaPagina),
            'current_page' => $pagina,
            'per_page' => $porPagina,
            'total' => $visibles->count(),
            'last_page' => max(1, (int) ceil($visibles->count() / $porPagina)),
            'counts' => Cobros::contar($sinFiltroDePago),
            'resumen' => Cobros::resumir($visibles),
            'lista_bulk' => Cobros::listaBulk($visibles),
        ]);
    }

    /**
     * Los turnos de la página con todas sus relaciones, en el mismo orden, cada
     * uno con su `cobro`.
     */
    private function detalle($user, $filasDeLaPagina): array
    {
        if ($filasDeLaPagina->isEmpty()) {
            return [];
        }

        $cobros = $filasDeLaPagina->mapWithKeys(fn (array $f) => [$f['turno']->id => $f['cobro']]);
        $completos = Turno::delUsuario($user)
            ->whereIn('id', $cobros->keys())
            ->with($this->relacionesDeListado())
            ->get();
        $this->decorarListado($completos);
        $porId = $completos->keyBy('id');

        return $filasDeLaPagina->map(function (array $f) use ($porId) {
            $turno = $porId[$f['turno']->id];
            $turno->setAttribute('cobro', $this->paraJson($f['cobro']));

            return $turno;
        })->values()->all();
    }

    /** El cobro sin lo interno (reserva_id) y con el dinero sin ceros de sobra. */
    private function paraJson(array $cobro): array
    {
        return [
            'finalizado' => $cobro['finalizado'],
            'pago' => $cobro['pago'],
            'precio' => $cobro['precio'] === null ? null : Cobros::monto($cobro['precio']),
            'cobrado' => $cobro['cobrado'] === null ? null : Cobros::monto($cobro['cobrado']),
            'sena' => Cobros::monto($cobro['sena']),
            'sena_compartida' => $cobro['sena_compartida'],
            'falta_fila' => $cobro['falta_fila'] === null ? null : Cobros::monto($cobro['falta_fila']),
            'precio_lista' => Cobros::monto($cobro['precio_lista']),
            'lista_completa' => $cobro['lista_completa'],
        ];
    }
}

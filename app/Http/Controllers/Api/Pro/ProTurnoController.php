<?php

namespace App\Http\Controllers\Api\Pro;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pro\ListarTurnosProfesionalRequest;
use App\Http\Requests\Pro\MarcasTurnosProfesionalRequest;
use App\Models\ProfesionalVinculo;
use App\Services\Portal\ConsultaTurnosProfesional;
use App\Services\Portal\TurnoPortalMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only agenda of the professional. The negocio and the professional come
 * from the link that `pro.vigente` validated and published as the `pro.vinculo`
 * request attribute; nothing here reads them from the request input.
 */
class ProTurnoController extends Controller
{
    public function __construct(
        private readonly ConsultaTurnosProfesional $consulta,
        private readonly TurnoPortalMapper $mapper,
    ) {}

    // GET /api/pro/turnos?fecha=YYYY-MM-DD | desde=...&hasta=... (default: today)
    public function index(ListarTurnosProfesionalRequest $request): JsonResponse
    {
        [$desde, $hasta] = $request->rango();

        $resultado = $this->consulta->listar($this->vinculo($request), $desde, $hasta, $request->palabras(), $request->servicioId());

        return response()->json([
            'data' => $resultado['turnos']->map(fn ($turno) => $this->mapper->mapear($turno))->values()->all(),
            'truncated' => $resultado['truncated'],
        ]);
    }

    // GET /api/pro/turnos/marcas?mes=YYYY-MM
    // Registered above turnos/{id} in routes/api.php; keep it that way.
    public function marcas(MarcasTurnosProfesionalRequest $request): JsonResponse
    {
        $mes = $request->mes();

        return response()->json([
            'mes' => $mes,
            'dias' => $this->consulta->marcas($this->vinculo($request), $mes),
        ]);
    }

    // GET /api/pro/turnos/{id}
    // The id is checked here, not with whereNumber(): a route constraint would
    // answer "abc" with the router's 404 body, which differs from the body of a
    // turno that is not hers. Every "not yours / not there" gets the same body.
    public function show(Request $request, string $id): JsonResponse
    {
        $turno = preg_match('/^[1-9]\d{0,17}\z/', $id) === 1
            ? $this->consulta->buscar($this->vinculo($request), (int) $id)
            : null;

        if ($turno === null) {
            return response()->json([
                'message' => 'No encontramos ese turno.',
                'code' => 'TURNO_NO_ENCONTRADO',
            ], 404);
        }

        return response()->json($this->mapper->mapear($turno));
    }

    private function vinculo(Request $request): ProfesionalVinculo
    {
        return $request->attributes->get('pro.vinculo');
    }
}

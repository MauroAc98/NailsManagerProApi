<?php

namespace App\Http\Controllers\Api\Pro;

use App\Http\Controllers\Controller;
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

    // GET /api/pro/turnos?fecha=YYYY-MM-DD (default: today)
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate(
            ['fecha' => ['nullable', 'date_format:Y-m-d']],
            ['fecha.date_format' => 'La fecha tiene que tener el formato AAAA-MM-DD.'],
        );

        $resultado = $this->consulta->listar($this->vinculo($request), $datos['fecha'] ?? null);

        return response()->json([
            'data' => $resultado['turnos']->map(fn ($turno) => $this->mapper->mapear($turno))->values()->all(),
            'truncated' => $resultado['truncated'],
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

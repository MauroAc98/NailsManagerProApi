<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingreso;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class IngresoController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/ingresos
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'desde' => 'sometimes|nullable|date',
            'hasta' => 'sometimes|nullable|date|after_or_equal:desde',
        ]);

        $query = Ingreso::delUsuario($request->user());

        if ($request->filled('desde') && $request->filled('hasta')) {
            $query->whereBetween('fecha', [$request->desde, $request->hasta]);
        } elseif ($request->filled('desde')) {
            $query->where('fecha', '>=', $request->desde);
        } elseif ($request->filled('hasta')) {
            $query->where('fecha', '<=', $request->hasta);
        }

        $ingresos = $query
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->get();

        return response()->json($ingresos);
    }

    // ─────────────────────────────────────────────
    // POST /api/ingresos
    // ─────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules($request));

        // Se fija explícito (en vez de confiar en el default de columna)
        // para que el JSON de respuesta del create ya lo refleje sin
        // necesitar un refresh — mismo criterio que GastoController.
        $data['descripcion'] = $data['descripcion'] ?? null;

        $ingreso = $request->user()->ingresos()->create($data);

        return response()->json($ingreso, 201);
    }

    // ─────────────────────────────────────────────
    // GET /api/ingresos/{id}
    // ─────────────────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        $ingreso = Ingreso::delUsuario($request->user())->findOrFail($id);

        return response()->json($ingreso);
    }

    // ─────────────────────────────────────────────
    // PUT /api/ingresos/{id}
    // ─────────────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate($this->rules($request, sometimes: true));

        $ingreso = Ingreso::delUsuario($request->user())->findOrFail($id);
        $ingreso->update($data);

        return response()->json($ingreso);
    }

    // ─────────────────────────────────────────────
    // DELETE /api/ingresos/{id}
    // ─────────────────────────────────────────────
    public function destroy(Request $request, int $id): JsonResponse
    {
        // Hard delete sin guardas: un ingreso no tiene referencias
        // dependientes — se borra siempre, y el frontend es responsable
        // de confirmar con el usuario antes de llamar esto.
        $ingreso = Ingreso::delUsuario($request->user())->findOrFail($id);
        $ingreso->delete();

        return response()->json([
            'message' => 'Ingreso eliminado correctamente.',
        ]);
    }

    // ─────────────────────────────────────────────
    // Reglas de validación compartidas entre store/update
    // ─────────────────────────────────────────────
    private function rules(Request $request, bool $sometimes = false): array
    {
        $required = $sometimes ? 'sometimes' : 'required';

        return [
            'fecha'       => "{$required}|date",
            'monto'       => "{$required}|numeric|gt:0",
            'categoria'   => [$required, Rule::in(Ingreso::CATEGORIAS)],
            'descripcion' => 'nullable|string|max:255',
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gasto;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class GastoController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/gastos
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'desde' => 'sometimes|nullable|date',
            'hasta' => 'sometimes|nullable|date|after_or_equal:desde',
        ]);

        $query = Gasto::delUsuario($request->user());

        if ($request->filled('desde') && $request->filled('hasta')) {
            $query->whereBetween('fecha', [$request->desde, $request->hasta]);
        } elseif ($request->filled('desde')) {
            $query->where('fecha', '>=', $request->desde);
        } elseif ($request->filled('hasta')) {
            $query->where('fecha', '<=', $request->hasta);
        }

        $gastos = $query
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->get();

        return response()->json($gastos);
    }

    // ─────────────────────────────────────────────
    // POST /api/gastos
    // ─────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules($request));

        // Se fijan explícitos (en vez de confiar en el default de columna)
        // para que el JSON de respuesta del create ya los refleje sin
        // necesitar un refresh — mismo criterio que 'es_promo' en Servicio.
        $data['descripcion'] = $data['descripcion'] ?? null;
        $data['profesional_id'] = $data['profesional_id'] ?? null;

        $gasto = $request->user()->gastos()->create($data);

        return response()->json($gasto, 201);
    }

    // ─────────────────────────────────────────────
    // GET /api/gastos/{id}
    // ─────────────────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        $gasto = Gasto::delUsuario($request->user())->findOrFail($id);

        return response()->json($gasto);
    }

    // ─────────────────────────────────────────────
    // PUT /api/gastos/{id}
    // ─────────────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate($this->rules($request, sometimes: true));

        $gasto = Gasto::delUsuario($request->user())->findOrFail($id);
        $gasto->update($data);

        return response()->json($gasto);
    }

    // ─────────────────────────────────────────────
    // DELETE /api/gastos/{id}
    // ─────────────────────────────────────────────
    public function destroy(Request $request, int $id): JsonResponse
    {
        // Hard delete sin guardas: a diferencia de Servicio, un gasto no
        // tiene referencias dependientes — se borra siempre, y el frontend
        // es responsable de confirmar con el usuario antes de llamar esto.
        $gasto = Gasto::delUsuario($request->user())->findOrFail($id);
        $gasto->delete();

        return response()->json([
            'message' => 'Gasto eliminado correctamente.',
        ]);
    }

    // ─────────────────────────────────────────────
    // Reglas de validación compartidas entre store/update
    // ─────────────────────────────────────────────
    private function rules(Request $request, bool $sometimes = false): array
    {
        $required = $sometimes ? 'sometimes' : 'required';

        return [
            'fecha'          => "{$required}|date",
            'monto'          => "{$required}|numeric|gt:0",
            'categoria'      => [$required, Rule::in(Gasto::CATEGORIAS)],
            'descripcion'    => 'nullable|string|max:255',
            'profesional_id' => [
                'nullable',
                'integer',
                Rule::exists('profesionales', 'id')->where(
                    fn($q) =>
                    $q->where('user_id', $request->user()->id)
                ),
            ],
        ];
    }
}

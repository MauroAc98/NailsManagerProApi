<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoriaServicio;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class CategoriaServicioController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/categorias-servicio
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $categorias = CategoriaServicio::delUsuario($request->user())
            ->orderBy('nombre')
            ->get();

        return response()->json($categorias);
    }

    // ─────────────────────────────────────────────
    // POST /api/categorias-servicio
    // ─────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules($request));

        $data['user_id'] = $request->user()->id;

        $categoria = CategoriaServicio::create($data);

        return response()->json($categoria, 201);
    }

    // ─────────────────────────────────────────────
    // GET /api/categorias-servicio/{id}
    // ─────────────────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        $categoria = CategoriaServicio::delUsuario($request->user())->findOrFail($id);

        return response()->json($categoria);
    }

    // ─────────────────────────────────────────────
    // PUT /api/categorias-servicio/{id}
    // ─────────────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate($this->rules($request, sometimes: true, ignoreId: $id));

        $categoria = CategoriaServicio::delUsuario($request->user())->findOrFail($id);
        $categoria->update($data);

        return response()->json($categoria);
    }

    // ─────────────────────────────────────────────
    // DELETE /api/categorias-servicio/{id}
    // ─────────────────────────────────────────────
    public function destroy(Request $request, int $id): JsonResponse
    {
        $categoria = CategoriaServicio::delUsuario($request->user())->findOrFail($id);

        if ($categoria->servicios()->exists()) {
            return response()->json([
                'message' => 'Esta categoría tiene servicios asignados, no se puede eliminar.',
            ], 409);
        }

        $categoria->delete();

        return response()->json([
            'message' => 'Categoría eliminada correctamente.',
        ]);
    }

    // ─────────────────────────────────────────────
    // Reglas de validación compartidas entre store/update
    // ─────────────────────────────────────────────
    private function rules(Request $request, bool $sometimes = false, ?int $ignoreId = null): array
    {
        $required = $sometimes ? 'sometimes' : 'required';

        return [
            'nombre' => [
                $required,
                'string',
                'max:150',
                // Case-insensitive a propósito (a diferencia de Servicio::nombre,
                // que es case-sensitive hoy) — regla closure en vez de
                // Rule::unique porque esa clase compara la columna tal cual,
                // sin poder reemplazar el match exacto por LOWER(nombre).
                function (string $attribute, mixed $value, \Closure $fail) use ($request, $ignoreId) {
                    $existe = CategoriaServicio::where('user_id', $request->user()->id)
                        ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($value)])
                        ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                        ->exists();

                    if ($existe) {
                        $fail('Ya existe una categoría con ese nombre.');
                    }
                },
            ],
        ];
    }
}

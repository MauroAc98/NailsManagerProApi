<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoriaServicio;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
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
    // POST /api/categorias-servicio/{id}/fondo-historia
    // Guarda (o reemplaza) el fondo de la historia de disponibilidad de la
    // categoria. Mismo contrato que profesionales/{id}/fondo-historia.
    // ─────────────────────────────────────────────
    public function subirFondoHistoria(Request $request, int $id): JsonResponse
    {
        // mimes explícito (no solo 'image'): 'image' acepta SVG con <script>.
        $request->validate([
            'imagen' => 'required|image|mimes:jpeg,png,jpg,webp,gif,bmp|max:5120', // 5MB
        ]);

        $categoria = CategoriaServicio::delUsuario($request->user())->findOrFail($id);
        $pathAnterior = $categoria->getRawOriginal('fondo_historia_path');

        // Subir y confirmar ANTES de borrar el anterior: si store() falla el
        // fondo previo sigue sirviendo.
        $path = $request->file('imagen')->store('fondos_historia', 'public');
        if (! $path) {
            return response()->json(['message' => 'No se pudo guardar el fondo. Intentá de nuevo.'], 500);
        }

        $categoria->update(['fondo_historia_path' => $path]);

        if ($pathAnterior) {
            Storage::disk('public')->delete($pathAnterior);
        }

        return response()->json($categoria);
    }

    // ─────────────────────────────────────────────
    // DELETE /api/categorias-servicio/{id}/fondo-historia
    // ─────────────────────────────────────────────
    public function borrarFondoHistoria(Request $request, int $id): JsonResponse
    {
        $categoria = CategoriaServicio::delUsuario($request->user())->findOrFail($id);

        if ($categoria->getRawOriginal('fondo_historia_path')) {
            Storage::disk('public')->delete($categoria->getRawOriginal('fondo_historia_path'));
        }

        $categoria->update(['fondo_historia_path' => null]);

        return response()->json($categoria);
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
                // que es case-sensitive hoy). La comparación se hace en PHP con
                // mb_strtolower sobre ambos lados, no con LOWER() de la base:
                // el LOWER() de sqlite no baja acentos/ñ ("MANÍA" queda "manÍa"),
                // y el de Postgres depende del locale del cluster — con nombres
                // de categoría en español (tildes, ñ) eso rompe justo el caso
                // que esta regla existe para cubrir. El set de categorías por
                // usuario es chico, así que traer y comparar en PHP es correcto
                // y portable en vez de rápido-pero-frágil.
                function (string $attribute, mixed $value, \Closure $fail) use ($request, $ignoreId) {
                    $valorNormalizado = mb_strtolower($value);

                    $existe = CategoriaServicio::where('user_id', $request->user()->id)
                        ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                        ->get(['id', 'nombre'])
                        ->contains(fn($categoria) => mb_strtolower($categoria->nombre) === $valorNormalizado);

                    if ($existe) {
                        $fail('Ya existe una categoría con ese nombre.');
                    }
                },
            ],
        ];
    }
}

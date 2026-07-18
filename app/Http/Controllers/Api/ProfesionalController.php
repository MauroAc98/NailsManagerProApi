<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profesional;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class ProfesionalController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/profesionales
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $profesionales = Profesional::delUsuario($request->user())
            ->with('servicios')
            ->orderBy('nombre')
            ->get();

        return response()->json($profesionales);
    }

    // ─────────────────────────────────────────────
    // POST /api/profesionales
    // ─────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre'          => 'required|string|max:255',
            'color'           => 'nullable|string|max:50',
            'servicio_ids'    => 'sometimes|array',
            'servicio_ids.*'  => [
                'integer',
                Rule::exists('servicios', 'id')->where(
                    fn($q) => $q->where('user_id', $request->user()->id)
                ),
            ],
        ]);

        $profesional = $request->user()->profesionales()->create([
            'nombre' => $data['nombre'],
            'color'  => $data['color'] ?? null,
            'activo' => true,
        ]);

        if (array_key_exists('servicio_ids', $data)) {
            $profesional->servicios()->sync($data['servicio_ids']);
        }

        return response()->json($profesional->load('servicios'), 201);
    }

    // ─────────────────────────────────────────────
    // PUT /api/profesionales/{id}
    // ─────────────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'nombre'          => 'sometimes|string|max:255',
            'color'           => 'sometimes|nullable|string|max:50',
            'activo'          => 'sometimes|boolean',
            'servicio_ids'    => 'sometimes|array',
            'servicio_ids.*'  => [
                'integer',
                Rule::exists('servicios', 'id')->where(
                    fn($q) => $q->where('user_id', $request->user()->id)
                ),
            ],
        ]);

        $profesional = Profesional::delUsuario($request->user())->findOrFail($id);

        $profesional->update(collect($data)->except('servicio_ids')->all());

        if (array_key_exists('servicio_ids', $data)) {
            $profesional->servicios()->sync($data['servicio_ids']);
        }

        return response()->json($profesional->load('servicios'));
    }

    // ─────────────────────────────────────────────
    // DELETE /api/profesionales/{id}
    // ─────────────────────────────────────────────
    public function destroy(Request $request, int $id): JsonResponse
    {
        $profesional = Profesional::delUsuario($request->user())->findOrFail($id);
        $profesional->update(['activo' => false]);

        return response()->json([
            'message' => 'Profesional desactivado correctamente.',
        ]);
    }
}

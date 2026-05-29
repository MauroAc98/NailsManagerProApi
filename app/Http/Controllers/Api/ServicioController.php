<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Servicio;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ServicioController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/servicios
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $servicios = Servicio::delUsuario($request->user())
            ->orderBy('nombre')
            ->get();

        return response()->json($servicios);
    }

    // ─────────────────────────────────────────────
    // POST /api/servicios
    // ─────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre'           => 'required|string|max:150',
            'duracion_minutos' => 'required|integer|min:1|max:480',
            'precio'           => 'nullable|numeric|min:0',
        ]);

        $servicio = $request->user()->servicios()->create($data);

        return response()->json($servicio, 201);
    }

    // ─────────────────────────────────────────────
    // GET /api/servicios/{id}
    // ─────────────────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        $servicio = Servicio::delUsuario($request->user())->findOrFail($id);

        return response()->json($servicio);
    }

    // ─────────────────────────────────────────────
    // PUT /api/servicios/{id}
    // ─────────────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'nombre'           => 'sometimes|string|max:150',
            'duracion_minutos' => 'sometimes|integer|min:1|max:480',
            'precio'           => 'nullable|numeric|min:0',
            'activo'           => 'sometimes|boolean',
        ]);

        $servicio = Servicio::delUsuario($request->user())->findOrFail($id);
        $servicio->update($data);

        return response()->json($servicio);
    }

    // ─────────────────────────────────────────────
    // DELETE /api/servicios/{id}
    // ─────────────────────────────────────────────
    public function destroy(Request $request, int $id): JsonResponse
    {
        $servicio = Servicio::delUsuario($request->user())->findOrFail($id);
        $servicio->update(['activo' => false]);

        return response()->json([
            'message' => 'Servicio desactivado correctamente.',
        ]);
    }
}
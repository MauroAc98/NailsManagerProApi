<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/clientes
    // Soporta: ?buscar=
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = Cliente::delUsuario($request->user());

        if ($request->filled('buscar')) {
            $buscar = $request->buscar;
            $query->where(function ($q) use ($buscar) {
                $q->where('nombre', 'ILIKE', "%{$buscar}%")
                  ->orWhere('apellido', 'ILIKE', "%{$buscar}%")
                  ->orWhere('telefono', 'LIKE', "%{$buscar}%");
            });
        }

        $clientes = $query->orderBy('apellido')->orderBy('nombre')->get();

        return response()->json($clientes);
    }

    // ─────────────────────────────────────────────
    // POST /api/clientes
    // ─────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre'   => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'telefono' => [
                'required',
                'string',
                'regex:/^\+[1-9]\d{7,14}$/',
                Rule::unique('clientes', 'telefono')
                    ->where('user_id', $request->user()->id),
            ],
        ], [
            'telefono.unique' => 'Ya existe una clienta registrada con ese teléfono.',
        ]);

        $cliente = $request->user()->clientes()->create($data);

        return response()->json($cliente, 201);
    }

    // ─────────────────────────────────────────────
    // GET /api/clientes/{id}
    // ─────────────────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        $cliente = Cliente::delUsuario($request->user())
            ->with(['turnos.servicios'])
            ->findOrFail($id);

        return response()->json($cliente);
    }

    // ─────────────────────────────────────────────
    // PUT /api/clientes/{id}
    // ─────────────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $cliente = Cliente::delUsuario($request->user())->findOrFail($id);

        $data = $request->validate([
            'nombre'   => 'sometimes|string|max:100',
            'apellido' => 'sometimes|string|max:100',
            'telefono' => [
                'sometimes',
                'string',
                'regex:/^\+[1-9]\d{7,14}$/',
                Rule::unique('clientes', 'telefono')
                    ->where('user_id', $request->user()->id)
                    ->ignore($cliente->id),
            ],
        ], [
            'telefono.unique' => 'Ya existe una clienta registrada con ese teléfono.',
        ]);

        $cliente->update($data);

        return response()->json($cliente);
    }

    // ─────────────────────────────────────────────
    // DELETE /api/clientes/{id}
    // ─────────────────────────────────────────────
    public function destroy(Request $request, int $id): JsonResponse
    {
        $cliente = Cliente::delUsuario($request->user())->findOrFail($id);

        $tieneTurnosFuturos = $cliente->turnos()
            ->confirmados()
            ->where('fecha_hora', '>=', now())
            ->exists();

        if ($tieneTurnosFuturos) {
            return response()->json([
                'message' => 'No se puede eliminar una clienta con turnos futuros confirmados.',
            ], 422);
        }

        $cliente->delete();

        return response()->json([
            'message' => 'Clienta eliminada correctamente.',
        ]);
    }
}
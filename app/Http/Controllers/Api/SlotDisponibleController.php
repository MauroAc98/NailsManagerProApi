<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SlotDisponible;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SlotDisponibleController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/slots
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $slots = SlotDisponible::delUsuario($request->user())
            ->orderBy('hora')
            ->get();

        return response()->json($slots);
    }

    // ─────────────────────────────────────────────
    // POST /api/slots
    // ─────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hora' => 'required|date_format:H:i',
        ]);

        // Verificar que no exista ese slot para esta profesional
        $existe = SlotDisponible::delUsuario($request->user())
            ->where('hora', $data['hora'])
            ->exists();

        if ($existe) {
            return response()->json([
                'message' => 'Ya existe un slot para ese horario.',
            ], 422);
        }

        $slot = $request->user()->slotsDisponibles()->create([
            'hora'   => $data['hora'],
            'activo' => true,
        ]);

        return response()->json($slot, 201);
    }

    // ─────────────────────────────────────────────
    // PUT /api/slots/{id}
    // Activa o desactiva un slot
    // ─────────────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'activo' => 'required|boolean',
        ]);

        $slot = SlotDisponible::delUsuario($request->user())->findOrFail($id);
        $slot->update($data);

        return response()->json($slot);
    }

    // ─────────────────────────────────────────────
    // DELETE /api/slots/{id}
    // ─────────────────────────────────────────────
    public function destroy(Request $request, int $id): JsonResponse
    {
        $slot = SlotDisponible::delUsuario($request->user())->findOrFail($id);
        $slot->delete();

        return response()->json([
            'message' => 'Slot eliminado correctamente.',
        ]);
    }
}
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profesional;
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
        $request->validate([
            'profesional_id' => 'sometimes|nullable|integer|exists:profesionales,id',
        ]);

        // Backward compat: sin profesional_id, resuelve al profesional
        // default de la cuenta (el más antiguo) — mismo mecanismo que
        // TurnoController::resolverProfesional. Para cualquier cuenta con un
        // solo Profesional (caso de hoy) esto es un no-op: ese Profesional ya
        // es dueño del 100% de sus slots gracias al backfill
        // (BackfillProfesionalesDefault), así que el resultado es idéntico al
        // de antes (delUsuario).
        $profesional = Profesional::resolverParaUsuario(
            $request->user(),
            $request->filled('profesional_id') ? (int) $request->profesional_id : null,
        );

        $slots = SlotDisponible::when(
            $profesional,
            fn ($q) => $q->where('profesional_id', $profesional->id),
            fn ($q) => $q->delUsuario($request->user()),
        )
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
            'hora'           => 'required|date_format:H:i',
            'profesional_id' => 'sometimes|nullable|integer|exists:profesionales,id',
        ]);

        // Backward compat: sin profesional_id, resuelve al profesional
        // default de la cuenta (el más antiguo) — mismo mecanismo que
        // TurnoController::resolverProfesional. Necesario para que el slot
        // siga siendo visible en TurnoController::disponibilidad, que
        // escopea por profesional_id en vez de por user_id — ver comentario
        // en la migración 2026_07_17_100002 sobre por qué profesional_id es
        // nullable pero siempre debería resolverse acá.
        $profesional = Profesional::resolverParaUsuario(
            $request->user(),
            $data['profesional_id'] ?? null,
        );

        // Verificar que no exista ese slot para esta profesional. Escopeado
        // por profesional_id (no por user_id): dos profesionales de la misma
        // cuenta pueden tener slots válidos en el mismo horario, cada una con
        // su propia agenda independiente — mismo criterio que
        // TurnoController::verificarChoque para turnos.
        $existe = SlotDisponible::when(
            $profesional,
            fn ($q) => $q->where('profesional_id', $profesional->id),
            fn ($q) => $q->delUsuario($request->user()),
        )
            ->where('hora', $data['hora'])
            ->exists();

        if ($existe) {
            return response()->json([
                'message' => 'Ya existe un slot para ese horario.',
            ], 422);
        }

        $slot = $request->user()->slotsDisponibles()->create([
            'profesional_id' => $profesional?->id,
            'hora'           => $data['hora'],
            'activo'         => true,
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
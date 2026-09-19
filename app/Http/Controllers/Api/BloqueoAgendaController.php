<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BloqueoAgenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BloqueoAgendaController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/bloqueos
    // Todos los bloqueos de la cuenta, sin filtrar por rango de fecha — el
    // dataset es chico por cuenta (mismo criterio que
    // CategoriaServicioController::index). El filtrado por rango para el
    // hot path publico/de disponibilidad vive en DisponibilidadService.
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $bloqueos = BloqueoAgenda::delUsuario($request->user())
            ->orderBy('fecha')
            ->get();

        return response()->json($bloqueos);
    }

    // ─────────────────────────────────────────────
    // POST /api/bloqueos
    // Ambos horarios ausentes = bloqueo de dia completo; ambos presentes =
    // bloqueo parcial. Un solo horario no es un estado valido — se rechaza
    // con 422 sobre el campo faltante.
    // ─────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'profesional_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('profesionales', 'id')->where(fn ($q) => $q->where('user_id', $user->id)),
            ],
            'fecha'      => 'required|date|after_or_equal:today',
            'hora_desde' => 'required_with:hora_hasta|nullable|date_format:H:i',
            'hora_hasta' => 'required_with:hora_desde|nullable|date_format:H:i|after:hora_desde',
            'motivo'     => 'nullable|string|max:255',
        ]);

        $profesionalId = $data['profesional_id'] ?? null;
        $horaDesde = $data['hora_desde'] ?? null;
        $horaHasta = $data['hora_hasta'] ?? null;

        // where(col, null) arma "col = NULL", que en SQL nunca matchea — hay
        // que usar whereNull explicito para los 3 campos opcionales.
        $duplicado = BloqueoAgenda::delUsuario($user)
            ->paraFecha($data['fecha'])
            ->when($profesionalId === null, fn ($q) => $q->whereNull('profesional_id'), fn ($q) => $q->where('profesional_id', $profesionalId))
            ->when($horaDesde === null, fn ($q) => $q->whereNull('hora_desde'), fn ($q) => $q->where('hora_desde', $horaDesde))
            ->when($horaHasta === null, fn ($q) => $q->whereNull('hora_hasta'), fn ($q) => $q->where('hora_hasta', $horaHasta))
            ->exists();

        if ($duplicado) {
            return response()->json([
                'message' => 'Ya existe un bloqueo idéntico para esa fecha.',
            ], 409);
        }

        $bloqueo = BloqueoAgenda::create([
            'user_id'        => $user->id,
            'profesional_id' => $profesionalId,
            'fecha'          => $data['fecha'],
            'hora_desde'     => $horaDesde,
            'hora_hasta'     => $horaHasta,
            'motivo'         => $data['motivo'] ?? null,
        ]);

        return response()->json($bloqueo, 201);
    }

    // ─────────────────────────────────────────────
    // DELETE /api/bloqueos/{id}
    // No hay update: editar un bloqueo es borrar + recrear, así el
    // invariante dia-completo-o-parcial se valida en un solo lugar (store).
    // ─────────────────────────────────────────────
    public function destroy(Request $request, int $id): JsonResponse
    {
        $bloqueo = BloqueoAgenda::delUsuario($request->user())->findOrFail($id);
        $bloqueo->delete();

        return response()->json(['message' => 'Bloqueo eliminado correctamente.']);
    }
}

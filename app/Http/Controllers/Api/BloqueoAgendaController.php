<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BloqueoAgenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}

<?php

namespace App\Http\Controllers\Api\Pro;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProAuthController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/pro/me
    // Stub for now: just the account. The chooser payload (links, negocio,
    // professional) arrives with the per-request validity slice.
    // ─────────────────────────────────────────────
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'cuenta' => ['email' => $request->user('profesional')->email],
        ]);
    }
}

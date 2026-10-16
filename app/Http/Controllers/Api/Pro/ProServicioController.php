<?php

namespace App\Http\Controllers\Api\Pro;

use App\Http\Controllers\Controller;
use App\Models\ProfesionalVinculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only list of the services assigned to the professional, for the filter
 * of her agenda. The professional and the negocio come from the link that
 * `pro.vigente` validated and published as the `pro.vinculo` request attribute;
 * nothing here reads them from the request input.
 */
class ProServicioController extends Controller
{
    // GET /api/pro/servicios
    // Only id and nombre: no price, duration, category or pivot data.
    public function index(Request $request): JsonResponse
    {
        /** @var ProfesionalVinculo $vinculo */
        $vinculo = $request->attributes->get('pro.vinculo');
        $profesional = $vinculo->profesional;

        // servicios.user_id is redundant with the pivot on purpose: a service of
        // another negocio attached to her by mistake must still stay out.
        // Promos are not on the pivot of a professional, but are excluded anyway.
        $servicios = $profesional->servicios()
            ->where('servicios.user_id', $profesional->user_id)
            ->where('servicios.activo', true)
            ->where('servicios.es_promo', false)
            ->orderBy('servicios.orden')
            ->orderBy('servicios.nombre')
            ->orderBy('servicios.id')
            ->select('servicios.id', 'servicios.nombre')
            ->get();

        return response()->json(
            $servicios->map(fn ($servicio) => ['id' => $servicio->id, 'nombre' => $servicio->nombre])->values()->all(),
        );
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Servicio;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ServicioController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/servicios
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $servicios = Servicio::delUsuario($request->user())
            ->orderBy('orden')
            ->get();

        return response()->json($servicios);
    }

    // ─────────────────────────────────────────────
    // POST /api/servicios
    // ─────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:150',
                Rule::unique('servicios')->where(
                    fn($q) =>
                    $q->where('user_id', $request->user()->id)
                ),
            ],
            'duracion_minutos' => 'required|integer|min:1|max:480',
            'precio'           => 'nullable|numeric|min:0',
            'es_promo'         => 'sometimes|boolean',
        ]);

        // Se fija explícito (en vez de confiar en el default de columna)
        // para que el JSON de respuesta del create ya lo refleje sin
        // necesitar un refresh — mismo criterio que 'activo' en Profesional.
        $data['es_promo'] = $data['es_promo'] ?? false;

        // El próximo 'orden' se calcula desde el máximo existente, no desde
        // el conteo de filas, mismo criterio que 'orden' en HistoriaPrecioFoto.
        $data['orden'] = ($request->user()->servicios()->max('orden') ?? -1) + 1;

        $servicio = $request->user()->servicios()->create($data);

        // Default "lo ofrece todo el mundo": un servicio nuevo queda
        // disponible para todas las profesionales activas de la cuenta.
        // Restringirlo a alguna puntual es la acción explícita, en
        // Configuración > Profesionales — no al revés.
        $profesionalIds = $request->user()->profesionales()->where('activo', true)->pluck('id');
        $servicio->profesionales()->sync($profesionalIds);

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
            'nombre' => [
                'sometimes',
                'string',
                'max:150',
                Rule::unique('servicios')->where(
                    fn($q) =>
                    $q->where('user_id', $request->user()->id)
                )->ignore($id),
            ],
            'duracion_minutos' => 'sometimes|integer|min:1|max:480',
            'precio'           => 'nullable|numeric|min:0',
            'activo'           => 'sometimes|boolean',
            'es_promo'         => 'sometimes|boolean',
        ]);

        $servicio = Servicio::delUsuario($request->user())->findOrFail($id);
        $servicio->update($data);

        // Mismo default "lo ofrece todo el mundo" que store(), para servicios
        // huérfanos (creados antes del auto-attach en store, o antes de que
        // la cuenta corriera el backfill). Si ya tiene alguna profesional
        // asignada, se respeta esa restricción explícita — no se toca.
        if ($servicio->profesionales()->count() === 0) {
            $profesionalIds = $request->user()->profesionales()->where('activo', true)->pluck('id');
            $servicio->profesionales()->sync($profesionalIds);
        }

        return response()->json($servicio);
    }

    // ─────────────────────────────────────────────
    // DELETE /api/servicios/{id}
    // ─────────────────────────────────────────────
    public function destroy(Request $request, int $id): JsonResponse
    {
        $servicio = Servicio::delUsuario($request->user())->findOrFail($id);

        if ($servicio->turnos()->exists()) {
            return response()->json([
                'message' => 'Este servicio tiene turnos asociados, no se puede eliminar. Desactivalo en su lugar.',
            ], 409);
        }

        // Hard delete es seguro: profesional_servicio tiene cascadeOnDelete
        // sobre servicio_id, así que ese pivot se limpia solo.
        $servicio->delete();

        return response()->json([
            'message' => 'Servicio eliminado correctamente.',
        ]);
    }

    // ─────────────────────────────────────────────
    // PATCH /api/servicios/reordenar
    // ─────────────────────────────────────────────
    public function reordenar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => [
                'integer',
                Rule::exists('servicios', 'id')->where(
                    fn($q) =>
                    $q->where('user_id', $request->user()->id)
                ),
            ],
        ]);

        DB::transaction(function () use ($request, $data) {
            foreach ($data['ids'] as $index => $id) {
                Servicio::delUsuario($request->user())
                    ->where('id', $id)
                    ->update(['orden' => $index]);
            }
        });

        return response()->json(Servicio::delUsuario($request->user())->orderBy('orden')->get());
    }
}

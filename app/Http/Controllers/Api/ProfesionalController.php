<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profesional;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfesionalController extends Controller
{
    // Tope server-side de fotos por profesional para la "historia de
    // precios". El frontend soft-asume 4 (sus layouts grid4/single/split2
    // no necesitan más), pero no lo valida — esto es defensa en profundidad
    // para no confiar en el cliente y evitar que se acumulen fotos sin
    // límite en el disco.
    private const MAX_FOTOS_HISTORIA_PRECIOS = 4;

    // ─────────────────────────────────────────────
    // GET /api/profesionales
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $profesionales = Profesional::delUsuario($request->user())
            ->with(['servicios', 'historiaPreciosFotos'])
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
            'nombre'                      => 'required|string|max:255',
            'color'                       => 'nullable|string|max:50',
            'historia_precios_layout_id'  => ['nullable', Rule::in(['grid4', 'single', 'split2'])],
            'historia_precios_estilo_id'  => ['nullable', Rule::in(['classic', 'modern', 'bold'])],
            'servicio_ids'    => 'sometimes|array',
            'servicio_ids.*'  => [
                'integer',
                Rule::exists('servicios', 'id')->where(
                    fn($q) => $q->where('user_id', $request->user()->id)
                ),
            ],
        ]);

        $profesional = $request->user()->profesionales()->create([
            'nombre'                     => $data['nombre'],
            'color'                      => $data['color'] ?? null,
            'activo'                     => true,
            'historia_precios_layout_id' => $data['historia_precios_layout_id'] ?? null,
            'historia_precios_estilo_id' => $data['historia_precios_estilo_id'] ?? null,
        ]);

        if (array_key_exists('servicio_ids', $data)) {
            $profesional->servicios()->sync($data['servicio_ids']);
        }

        return response()->json($profesional->load(['servicios', 'historiaPreciosFotos']), 201);
    }

    // ─────────────────────────────────────────────
    // PUT /api/profesionales/{id}
    // ─────────────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'nombre'                      => 'sometimes|string|max:255',
            'color'                       => 'sometimes|nullable|string|max:50',
            'activo'                      => 'sometimes|boolean',
            'historia_precios_layout_id'  => ['sometimes', 'nullable', Rule::in(['grid4', 'single', 'split2'])],
            'historia_precios_estilo_id'  => ['sometimes', 'nullable', Rule::in(['classic', 'modern', 'bold'])],
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

        return response()->json($profesional->load(['servicios', 'historiaPreciosFotos']));
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

    // ─────────────────────────────────────────────
    // POST /api/profesionales/{id}/fondo-historia
    // Guarda (o reemplaza) el fondo fijo usado por default al generar la
    // historia de disponibilidad de esta profesional.
    // ─────────────────────────────────────────────
    public function subirFondoHistoria(Request $request, int $id): JsonResponse
    {
        // mimes explícito (no solo 'image'): la regla 'image' de Laravel
        // acepta SVG, que puede traer <script> embebido — mismo riesgo que
        // AuthController::subirLogo, ver comentario ahí.
        $request->validate([
            'imagen' => 'required|image|mimes:jpeg,png,jpg,webp,gif,bmp|max:5120', // 5MB
        ]);

        $profesional = Profesional::delUsuario($request->user())->findOrFail($id);
        $pathAnterior = $profesional->getRawOriginal('fondo_historia_path');

        // Subir y confirmar ANTES de borrar el archivo anterior — si
        // store() falla, el fondo previo tiene que seguir sirviendo en vez
        // de quedar huérfano sin haberse reemplazado.
        $path = $request->file('imagen')->store('fondos_historia', 'public');
        if (!$path) {
            return response()->json(['message' => 'No se pudo guardar el fondo. Intentá de nuevo.'], 500);
        }

        $profesional->update(['fondo_historia_path' => $path]);

        if ($pathAnterior) {
            Storage::disk('public')->delete($pathAnterior);
        }

        return response()->json($profesional);
    }

    // ─────────────────────────────────────────────
    // DELETE /api/profesionales/{id}/fondo-historia
    // Quita el fondo fijo — vuelve a generar la historia sin fondo por
    // default hasta que se guarde uno nuevo.
    // ─────────────────────────────────────────────
    public function borrarFondoHistoria(Request $request, int $id): JsonResponse
    {
        $profesional = Profesional::delUsuario($request->user())->findOrFail($id);

        if ($profesional->getRawOriginal('fondo_historia_path')) {
            Storage::disk('public')->delete($profesional->getRawOriginal('fondo_historia_path'));
        }

        $profesional->update(['fondo_historia_path' => null]);

        return response()->json($profesional);
    }

    // ─────────────────────────────────────────────
    // POST /api/profesionales/{id}/historia-precios-fotos
    // Agrega una foto a la "historia de precios" (dynamic price story).
    // Devuelve el Profesional completo — el store del frontend reemplaza
    // la entidad entera en su reducer, igual que con fondo-historia.
    // ─────────────────────────────────────────────
    public function subirHistoriaPreciosFoto(Request $request, int $id): JsonResponse
    {
        // mimes explícito (no solo 'image'): la regla 'image' de Laravel
        // acepta SVG, que puede traer <script> embebido — mismo riesgo que
        // AuthController::subirLogo, ver comentario ahí.
        $request->validate([
            'imagen' => 'required|image|mimes:jpeg,png,jpg,webp,gif,bmp|max:5120', // 5MB
        ]);

        $profesional = Profesional::delUsuario($request->user())->findOrFail($id);

        $cantidadActual = $profesional->historiaPreciosFotos()->count();

        if ($cantidadActual >= self::MAX_FOTOS_HISTORIA_PRECIOS) {
            throw ValidationException::withMessages([
                'imagen' => ['Ya alcanzaste el máximo de ' . self::MAX_FOTOS_HISTORIA_PRECIOS . ' fotos para la historia de precios.'],
            ]);
        }

        $path = $request->file('imagen')->store('historia_precios_fotos', 'public');

        // El próximo 'orden' se calcula desde el máximo existente, no desde
        // el conteo de filas: si hubo un borrado antes de esta subida (el
        // flujo de "reemplazar una foto" es delete + re-upload), el conteo
        // ya no coincide con el próximo hueco libre y puede repetir el
        // 'orden' de una foto que sigue existiendo.
        $siguienteOrden = ($profesional->historiaPreciosFotos()->max('orden') ?? -1) + 1;

        $profesional->historiaPreciosFotos()->create([
            'path'  => $path,
            'orden' => $siguienteOrden,
        ]);

        return response()->json($profesional->load(['servicios', 'historiaPreciosFotos']));
    }

    // ─────────────────────────────────────────────
    // DELETE /api/profesionales/{id}/historia-precios-fotos/{fotoId}
    // Borra una foto de la "historia de precios" — fila y archivo del
    // disco, mismo criterio que fondo-historia: no dejar huérfanos.
    // ─────────────────────────────────────────────
    public function borrarHistoriaPreciosFoto(Request $request, int $id, int $fotoId): JsonResponse
    {
        $profesional = Profesional::delUsuario($request->user())->findOrFail($id);

        $foto = $profesional->historiaPreciosFotos()->findOrFail($fotoId);

        Storage::disk('public')->delete($foto->getRawOriginal('path'));
        $foto->delete();

        return response()->json($profesional->load(['servicios', 'historiaPreciosFotos']));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profesional;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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

    // Reglas de validación para 'historia_precios_nota', usadas en update()
    // — store() no acepta este campo al crear (una profesional nueva
    // siempre arranca sin nota, igual que sin fotos). Objeto por modo
    // (precios/promociones), cada uno con su propio texto/activa/alineacion.
    // `sometimes` en cada hoja porque el frontend PUEDE mandar solo el modo
    // que cambió — ver update(): el valor validado se mergea con el
    // historia_precios_nota existente en vez de reemplazarlo entero, así un
    // payload parcial nunca borra el modo que no vino en este request. 180
    // de tope server-side, mismo límite que ya aplica el textarea del
    // frontend — defensa en profundidad, no confiar en el cliente (mismo
    // criterio que MAX_FOTOS_HISTORIA_PRECIOS).
    private function reglasHistoriaPreciosNota(): array
    {
        return [
            'historia_precios_nota'                       => 'sometimes|nullable|array',
            'historia_precios_nota.precios'                => 'sometimes|array',
            'historia_precios_nota.precios.texto'          => 'sometimes|nullable|string|max:180',
            'historia_precios_nota.precios.activa'         => 'sometimes|boolean',
            'historia_precios_nota.precios.alineacion'     => ['sometimes', Rule::in(['left', 'center', 'right', 'justify'])],
            'historia_precios_nota.promociones'            => 'sometimes|array',
            'historia_precios_nota.promociones.texto'      => 'sometimes|nullable|string|max:180',
            'historia_precios_nota.promociones.activa'     => 'sometimes|boolean',
            'historia_precios_nota.promociones.alineacion' => ['sometimes', Rule::in(['left', 'center', 'right', 'justify'])],
        ];
    }

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
            'nombre'                        => 'required|string|max:255',
            'apellido'                      => 'nullable|string|max:255',
            'color'                         => 'nullable|string|max:50',
            'historia_precios_template_id'  => ['nullable', Rule::in(['feature', 'fullbleed', 'split', 'beforeafter', 'collage', 'grid', 'catalog', 'listphoto'])],
            'servicio_ids'    => 'sometimes|array',
            'servicio_ids.*'  => [
                'integer',
                Rule::exists('servicios', 'id')->where(
                    fn($q) => $q->where('user_id', $request->user()->id)
                ),
            ],
        ]);

        $profesional = $request->user()->profesionales()->create([
            'nombre'                        => $data['nombre'],
            'apellido'                      => $data['apellido'] ?? null,
            'color'                         => $data['color'] ?? null,
            'activo'                        => true,
            'historia_precios_template_id'  => $data['historia_precios_template_id'] ?? null,
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
            'nombre'                        => 'sometimes|string|max:255',
            'apellido'                      => 'sometimes|nullable|string|max:255',
            'color'                         => 'sometimes|nullable|string|max:50',
            'activo'                        => 'sometimes|boolean',
            'historia_precios_template_id'  => ['sometimes', 'nullable', Rule::in(['feature', 'fullbleed', 'split', 'beforeafter', 'collage', 'grid', 'catalog', 'listphoto'])],
            'servicio_ids'    => 'sometimes|array',
            'servicio_ids.*'  => [
                'integer',
                Rule::exists('servicios', 'id')->where(
                    fn($q) => $q->where('user_id', $request->user()->id)
                ),
            ],
            ...$this->reglasHistoriaPreciosNota(),
        ]);

        $profesional = Profesional::delUsuario($request->user())->findOrFail($id);

        // Merge por modo, NO reemplazo entero de la columna — un payload
        // que solo trae 'precios' (la validación de arriba lo permite,
        // 'sometimes' en cada hoja) no debe borrar 'promociones' ya
        // guardado. array_merge alcanza porque el merge es a nivel de las
        // claves de modo (precios/promociones): cada una llega completa
        // desde el frontend, nunca un modo parcial dentro de sí mismo (ver
        // useHistoriaPrecios, siempre manda notaState entero). Si el
        // request manda `historia_precios_nota: null` explícito (borrar
        // todo), se respeta tal cual, sin mergear.
        if (array_key_exists('historia_precios_nota', $data) && $data['historia_precios_nota'] !== null) {
            $data['historia_precios_nota'] = array_merge(
                $profesional->historia_precios_nota ?? [],
                $data['historia_precios_nota']
            );
        }

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

    // ─────────────────────────────────────────────
    // PATCH /api/profesionales/{id}/historia-precios-fotos/reordenar
    // ─────────────────────────────────────────────
    public function reordenarHistoriaPreciosFotos(Request $request, int $id): JsonResponse
    {
        $profesional = Profesional::delUsuario($request->user())->findOrFail($id);

        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => [
                'integer',
                Rule::exists('historia_precios_fotos', 'id')->where(
                    fn($q) => $q->where('profesional_id', $profesional->id)
                ),
            ],
        ]);

        DB::transaction(function () use ($profesional, $data) {
            foreach ($data['ids'] as $index => $fotoId) {
                $profesional->historiaPreciosFotos()
                    ->where('id', $fotoId)
                    ->update(['orden' => $index]);
            }
        });

        return response()->json($profesional->load(['servicios', 'historiaPreciosFotos']));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Servicio;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServicioController extends Controller
{
    // Tope server-side de fotos por servicio para el portafolio. Mismo
    // criterio de defensa en profundidad que
    // ProfesionalController::MAX_FOTOS_HISTORIA_PRECIOS: no confiar en que
    // el cliente respete el límite.
    private const MAX_FOTOS_SERVICIO = 12;

    // ─────────────────────────────────────────────
    // GET /api/servicios
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        // ->with('fotos'): las mutaciones de fotos (arriba) ya devolvian
        // $servicio->load('fotos'), pero index/show/update no cargaban la
        // relacion — al recargar la pantalla de edicion, las fotos ya
        // subidas parecian haber desaparecido hasta la primera mutacion de
        // esa sesion. Gap real encontrado al conectar el frontend.
        $servicios = Servicio::delUsuario($request->user())
            ->with('fotos')
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
            'categoria_id'     => [
                'nullable',
                'integer',
                Rule::exists('categorias_servicio', 'id')->where(
                    fn($q) =>
                    $q->where('user_id', $request->user()->id)
                ),
            ],
        ]);

        // Se fija explícito (en vez de confiar en el default de columna)
        // para que el JSON de respuesta del create ya lo refleje sin
        // necesitar un refresh — mismo criterio que 'activo' en Profesional.
        $data['es_promo'] = $data['es_promo'] ?? false;

        // Igual que 'es_promo': explícito para que el create ya lo refleje.
        // Ausente en el payload = null (sin categoría), no un error.
        $data['categoria_id'] = $data['categoria_id'] ?? null;

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

        return response()->json($servicio->load('fotos'), 201);
    }

    // ─────────────────────────────────────────────
    // GET /api/servicios/{id}
    // ─────────────────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        $servicio = Servicio::delUsuario($request->user())->with('fotos')->findOrFail($id);

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
            'categoria_id'     => [
                'nullable',
                'integer',
                Rule::exists('categorias_servicio', 'id')->where(
                    fn($q) =>
                    $q->where('user_id', $request->user()->id)
                ),
            ],
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

        return response()->json($servicio->load('fotos'));
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

    // ─────────────────────────────────────────────
    // POST /api/servicios/{id}/fotos
    // Agrega una foto al portafolio de este servicio. Devuelve el Servicio
    // completo — mismo criterio que
    // ProfesionalController::subirHistoriaPreciosFoto.
    // ─────────────────────────────────────────────
    public function subirFoto(Request $request, int $id): JsonResponse
    {
        // mimes explícito (no solo 'image'): la regla 'image' de Laravel
        // acepta SVG, que puede traer <script> embebido — mismo riesgo que
        // AuthController::subirLogo, ver comentario ahí.
        $request->validate([
            'imagen' => 'required|image|mimes:jpeg,png,jpg,webp,gif,bmp|max:5120', // 5MB
        ]);

        $servicio = Servicio::delUsuario($request->user())->findOrFail($id);

        $cantidadActual = $servicio->fotos()->count();

        if ($cantidadActual >= self::MAX_FOTOS_SERVICIO) {
            throw ValidationException::withMessages([
                'imagen' => ['Ya alcanzaste el máximo de ' . self::MAX_FOTOS_SERVICIO . ' fotos para este servicio.'],
            ]);
        }

        $path = $request->file('imagen')->store('servicio_fotos', 'public');

        // El próximo 'orden' se calcula desde el máximo existente, no desde
        // el conteo de filas — mismo criterio que
        // ProfesionalController::subirHistoriaPreciosFoto (ver comentario
        // ahí sobre el flujo de "reemplazar" = delete + re-upload).
        $siguienteOrden = ($servicio->fotos()->max('orden') ?? -1) + 1;

        $servicio->fotos()->create([
            'path'  => $path,
            'orden' => $siguienteOrden,
        ]);

        return response()->json($servicio->load('fotos'));
    }

    // ─────────────────────────────────────────────
    // DELETE /api/servicios/{id}/fotos/{fotoId}
    // Borra una foto del portafolio — fila y archivo del disco, mismo
    // criterio que ProfesionalController::borrarHistoriaPreciosFoto.
    // ─────────────────────────────────────────────
    public function borrarFoto(Request $request, int $id, int $fotoId): JsonResponse
    {
        $servicio = Servicio::delUsuario($request->user())->findOrFail($id);

        $foto = $servicio->fotos()->findOrFail($fotoId);

        Storage::disk('public')->delete($foto->getRawOriginal('path'));
        $foto->delete();

        return response()->json($servicio->load('fotos'));
    }

    // ─────────────────────────────────────────────
    // PATCH /api/servicios/{id}/fotos/reordenar
    // ─────────────────────────────────────────────
    public function reordenarFotos(Request $request, int $id): JsonResponse
    {
        $servicio = Servicio::delUsuario($request->user())->findOrFail($id);

        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => [
                'integer',
                Rule::exists('servicio_fotos', 'id')->where(
                    fn($q) => $q->where('servicio_id', $servicio->id)
                ),
            ],
        ]);

        DB::transaction(function () use ($servicio, $data) {
            foreach ($data['ids'] as $index => $fotoId) {
                $servicio->fotos()
                    ->where('id', $fotoId)
                    ->update(['orden' => $index]);
            }
        });

        return response()->json($servicio->load('fotos'));
    }
}

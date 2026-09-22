<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoriaServicio;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\User;
use App\Services\Reservas\DisponibilidadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PublicController extends Controller
{
    private const MAX_DIAS_RANGO = 45;

    // ─────────────────────────────────────────────
    // Helper — busca la profesional por slug
    // y verifica que esté activa
    // ─────────────────────────────────────────────
    private function getProfesional(string $slug): User
    {
        $user = User::where('slug', $slug)->firstOrFail();

        // 404 (no 403) para no revelar que existe un negocio con
        // suscripcion vencida/suspendida. Misma regla que CheckSubscription.
        if ($user->suscripcionVencida()) {
            abort(404);
        }

        return $user;
    }

    // ─────────────────────────────────────────────
    // GET /api/public/{slug}/info
    // Datos públicos del estudio
    // ─────────────────────────────────────────────
    public function info(string $slug): JsonResponse
    {
        $user = $this->getProfesional($slug);

        $profesionales = $user->profesionales()
            ->where('activo', true)
            ->orderBy('id')
            ->get(['id', 'nombre', 'avatar_path'])
            ->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre, 'avatar_url' => $p->avatar_url])
            ->values();

        return response()->json([
            'nombre' => $user->name,
            'logo_url' => $user->logo_url,
            'direccion' => $user->direccion,
            'profesionales' => $profesionales,
            // Fase 1 de Mercado Pago: sin esto conectado, el negocio no puede
            // cobrar la sena y la reserva online no tiene sentido de arrancar
            // (el frontend bloquea el flujo entero con este campo en falso).
            'pago_habilitado' => $user->sena_monto > 0 && $user->mpCredentials !== null,
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/public/{slug}/branding
    // Datos mínimos para el login personalizado por negocio (logo +
    // nombre). A diferencia de getProfesional(), NO valida "activo": ese
    // campo se eliminó de la tabla users (ver migración
    // 2026_06_22_170728_remove_activo_fecha_vencimiento_from_users_table)
    // y hoy getProfesional() siempre aborta con 403 por leerlo igual —
    // bug preexistente fuera del alcance de este cambio. El login debe
    // poder mostrar el branding del negocio aunque esté inactivo/vencido.
    // ─────────────────────────────────────────────
    public function branding(string $slug): JsonResponse
    {
        $user = User::where('slug', $slug)->firstOrFail();

        return response()->json([
            'nombre' => $user->name,
            'logo_url' => $user->logo_url,
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/public/{slug}/servicios
    // Lista de servicios activos del estudio
    // ─────────────────────────────────────────────
    public function servicios(Request $request, string $slug): JsonResponse
    {
        $user = $this->getProfesional($slug);

        $query = Servicio::where('user_id', $user->id)->where('activo', true);

        // Con profesional_id: solo los servicios que esa profesional ofrece
        // (pivot profesional_servicio). 404 si es de otro salon o esta inactiva.
        if ($request->filled('profesional_id')) {
            $profesional = Profesional::resolverParaUsuario($user, (int) $request->query('profesional_id'));
            $query->whereIn('id', $profesional->servicios()->pluck('servicios.id'));
        }

        // Solo categorias del propio salon (nunca se filtra la de otro tenant).
        $categorias = CategoriaServicio::where('user_id', $user->id)->pluck('nombre', 'id');

        $servicios = $query
            ->with('fotos')
            ->get(['id', 'nombre', 'duracion_minutos', 'precio', 'categoria_id', 'orden'])
            // Mismo orden que la lista del salon: categoria alfabetica, luego
            // orden/id; sin categoria al final.
            ->sortBy([
                fn ($a, $b) => (isset($categorias[$a->categoria_id]) ? 0 : 1) <=> (isset($categorias[$b->categoria_id]) ? 0 : 1),
                fn ($a, $b) => strcasecmp($categorias[$a->categoria_id] ?? '', $categorias[$b->categoria_id] ?? ''),
                fn ($a, $b) => $a->orden <=> $b->orden,
                fn ($a, $b) => $a->id <=> $b->id,
            ])
            ->map(fn ($s) => [
                'id' => $s->id,
                'nombre' => $s->nombre,
                'duracion_minutos' => (int) $s->duracion_minutos,
                'precio' => $s->precio + 0,
                'categoria' => isset($categorias[$s->categoria_id])
                    ? ['id' => $s->categoria_id, 'nombre' => $categorias[$s->categoria_id]]
                    : null,
                // Solo URLs planas, ordenadas — nunca el 'id' de la fila ni
                // la 'path' relativa del disco (ver ServicioFoto::url).
                'fotos' => $s->fotos->pluck('url')->values(),
            ])
            ->values();

        return response()->json($servicios);
    }

    // ─────────────────────────────────────────────
    // GET /api/public/{slug}/disponibilidad?fecha=
    // Slots disponibles para una fecha
    // ─────────────────────────────────────────────
    public function disponibilidad(Request $request, string $slug, DisponibilidadService $disponibilidad): JsonResponse
    {
        $data = $request->validate([
            'fecha' => 'required|date_format:Y-m-d|after_or_equal:today',
            'servicio_ids' => 'required|array|min:1',
            'servicio_ids.*' => 'integer',
            'profesional_id' => 'nullable|integer',
        ]);

        $user = $this->getProfesional($slug);

        $resuelto = $this->resolverServiciosYProfesional($user, $data);
        if ($resuelto instanceof JsonResponse) {
            return $resuelto;
        }
        [$servicios, $profesional] = $resuelto;

        $slots = $disponibilidad->calcular(
            $user,
            $data['fecha'],
            $servicios,
            $profesional,
            Carbon::now(),
            (int) config('reservas.anticipacion_minutos', 120),
        );

        return response()->json([
            'fecha' => $data['fecha'],
            'duracion_total_minutos' => (int) $servicios->sum('duracion_minutos'),
            'slots' => $slots,
        ]);
    }

    /**
     * Valida servicios (activos y del salon) y profesional (404 si es ajena o
     * inactiva; 422 si no ofrece todos los servicios). Comun a los dos
     * endpoints de disponibilidad.
     *
     * @return array{0: Collection, 1: ?Profesional}|JsonResponse
     */
    private function resolverServiciosYProfesional(User $user, array $data): array|JsonResponse
    {
        $ids = array_values(array_unique($data['servicio_ids']));
        $servicios = Servicio::where('user_id', $user->id)
            ->where('activo', true)
            ->whereIn('id', $ids)
            ->get();

        if ($servicios->count() !== count($ids)) {
            return response()->json(['message' => 'Uno o más servicios no son válidos.'], 422);
        }

        $profesional = null;
        if (! empty($data['profesional_id'])) {
            $profesional = Profesional::resolverParaUsuario($user, (int) $data['profesional_id']);
            $ofrecidos = $profesional->servicios()->pluck('servicios.id')->all();

            if (count(array_diff($ids, $ofrecidos)) > 0) {
                return response()->json(['message' => 'La profesional no ofrece todos los servicios elegidos.'], 422);
            }
        }

        return [$servicios, $profesional];
    }

    // ─────────────────────────────────────────────
    // GET /api/public/{slug}/disponibilidad/dias?desde=&hasta=
    // Dias del rango con al menos un inicio libre (y cuantos)
    // ─────────────────────────────────────────────
    public function disponibilidadDias(Request $request, string $slug, DisponibilidadService $disponibilidad): JsonResponse
    {
        $data = $request->validate([
            'desde' => 'required|date_format:Y-m-d',
            'hasta' => 'required|date_format:Y-m-d|after_or_equal:desde',
            'servicio_ids' => 'required|array|min:1',
            'servicio_ids.*' => 'integer',
            'profesional_id' => 'nullable|integer',
        ]);

        $desde = Carbon::parse($data['desde'])->startOfDay();
        $hasta = Carbon::parse($data['hasta'])->startOfDay();
        if ($desde->diffInDays($hasta) + 1 > self::MAX_DIAS_RANGO) {
            return response()->json(['message' => 'El rango no puede superar '.self::MAX_DIAS_RANGO.' días.'], 422);
        }

        $user = $this->getProfesional($slug);

        $resuelto = $this->resolverServiciosYProfesional($user, $data);
        if ($resuelto instanceof JsonResponse) {
            return $resuelto;
        }
        [$servicios, $profesional] = $resuelto;

        // Recorte a [hoy, hoy + ventana].
        $ahora = Carbon::now();
        $hoy = $ahora->copy()->startOfDay();
        $limite = $hoy->copy()->addDays(max(0, (int) config('reservas.ventana_dias', 30)));
        if ($desde->lt($hoy)) {
            $desde = $hoy;
        }
        if ($hasta->gt($limite)) {
            $hasta = $limite;
        }
        if ($desde->gt($hasta)) {
            return response()->json(['dias' => []]);
        }

        $libres = $disponibilidad->contarLibresPorDia(
            $user,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
            $servicios,
            $profesional,
            $ahora,
            (int) config('reservas.anticipacion_minutos', 120),
        );

        $dias = [];
        foreach ($libres as $fecha => $cantidad) {
            $dias[] = ['fecha' => (string) $fecha, 'libres' => $cantidad];
        }

        return response()->json(['dias' => $dias]);
    }
}

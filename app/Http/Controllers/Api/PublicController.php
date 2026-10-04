<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoriaServicio;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\User;
use App\Services\Reservas\DisponibilidadService;
use App\Services\Reservas\GrupoSuelto;
use App\Services\Reservas\MercadoPagoService;
use App\Services\Reservas\PromoInput;
use App\Services\Servicios\PromoComponentes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
    public function info(string $slug, DisponibilidadService $disponibilidad): JsonResponse
    {
        $user = $this->getProfesional($slug);

        // Solo las que tienen horarios cargados: sin slots nunca tienen un turno
        // libre, y mostrarlas ofrece algo que no se puede concretar.
        $profesionales = $user->profesionales()
            ->where('activo', true)
            ->whereIn('id', $disponibilidad->idsConHorarios($user))
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
    // GET /api/public/{slug}/terminos
    // Condiciones de la reserva online: antes de esto el frontend mostraba
    // "cancelación gratis hasta X h antes" con un numero fijo del lado del
    // cliente, igual para cualquier negocio real — una promesa sin backend
    // detras. ventana_pago_minutos usa el valor normal (no el de alta
    // ocupación): esto se pide UNA vez al entrar al flujo, antes de que
    // exista una reserva puntual a la que aplicarle esa regla.
    // ─────────────────────────────────────────────
    public function terminos(string $slug, MercadoPagoService $mercadoPago): JsonResponse
    {
        $user = $this->getProfesional($slug);

        return response()->json([
            // Lo que se le cobra a la clienta, no el neto que pidió el
            // negocio — tiene que coincidir con lo que ve en el checkout de
            // MP (ver MercadoPagoService::montoACobrar).
            'deposito' => $mercadoPago->montoACobrar((float) ($user->sena_monto ?? 0)),
            'ventana_pago_minutos' => (int) config('reservas.pago_minutos'),
            'anticipacion_minutos' => (int) config('reservas.anticipacion_minutos'),
            'ventana_cancelacion_horas' => (int) config('reservas.cancelacion_horas'),
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
    public function servicios(Request $request, string $slug, DisponibilidadService $disponibilidad): JsonResponse
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

        // Una promo componentizada fija a la profesional de cada componente: si
        // alguna esta inactiva, ya no ofrece ese servicio o el servicio esta
        // inactivo, la promo no se puede reservar y no se lista.
        // Solo cuentan las que tienen horarios: sin slots no hay turno posible.
        $activas = $disponibilidad->idsConHorarios($user);
        $ofrecidos = DB::table('profesional_servicio')->get(['profesional_id', 'servicio_id'])
            ->map(fn ($r) => "{$r->profesional_id}:{$r->servicio_id}")->flip();
        $serviciosActivos = Servicio::where('user_id', $user->id)->where('activo', true)->pluck('id')->flip();
        // Servicios que al menos una profesional con horarios ofrece: sin eso, elegirlo
        // no tiene como concretarse (nadie lo atiende).
        $conProfesional = DB::table('profesional_servicio')->whereIn('profesional_id', $activas)->pluck('servicio_id')->flip();

        $servicios = $query
            ->with(['fotos', 'componentes.componenteServicio:id,nombre,duracion_minutos', 'componentes.profesional:id,nombre,avatar_path'])
            ->get(['id', 'nombre', 'duracion_minutos', 'precio', 'categoria_id', 'orden', 'es_promo', 'modo_promo'])
            ->filter(fn ($s) => $s->es_promo && $s->componentes->isNotEmpty()
                ? $s->componentes->every(
                    fn ($c) => in_array($c->profesional_id, $activas, true)
                        && isset($ofrecidos["{$c->profesional_id}:{$c->componente_servicio_id}"])
                        && isset($serviciosActivos[$c->componente_servicio_id]),
                )
                : isset($conProfesional[$s->id]))
            // Mismo orden que la lista del salon: categoria alfabetica, luego
            // orden/id; sin categoria al final.
            ->sortBy([
                fn ($a, $b) => (isset($categorias[$a->categoria_id]) ? 0 : 1) <=> (isset($categorias[$b->categoria_id]) ? 0 : 1),
                fn ($a, $b) => strcasecmp($categorias[$a->categoria_id] ?? '', $categorias[$b->categoria_id] ?? ''),
                fn ($a, $b) => $a->orden <=> $b->orden,
                fn ($a, $b) => $a->id <=> $b->id,
            ])
            ->map(function ($s) use ($categorias) {
                $componentizada = $s->es_promo && $s->componentes->isNotEmpty();

                return [
                    'id' => $s->id,
                    'nombre' => $s->nombre,
                    'duracion_minutos' => (int) $s->duracion_minutos,
                    'precio' => $s->precio + 0,
                    // Promo con componentes: las profesionales las fija la promo, la
                    // clienta no elige (y se reserva sola, no combinada con otros).
                    'es_promo_componentizada' => $componentizada,
                    'categoria' => isset($categorias[$s->categoria_id])
                        ? ['id' => $s->categoria_id, 'nombre' => $categorias[$s->categoria_id]]
                        : null,
                    // Solo URLs planas, ordenadas — nunca el 'id' de la fila ni
                    // la 'path' relativa del disco (ver ServicioFoto::url).
                    'fotos' => $s->fotos->pluck('url')->values(),
                ] + ($componentizada ? [
                    // Detalle para mostrar en la tarjeta: nombres, duracion y avatar (sin ids) y
                    // en orden de ejecucion. Sin modo guardado rige la secuencia.
                    'modo_promo' => $s->modo_promo,
                    'componentes' => $s->componentes->map(fn ($c) => [
                        'servicio_nombre' => $c->componenteServicio->nombre,
                        'duracion_minutos' => (int) $c->componenteServicio->duracion_minutos,
                        'profesional_nombre' => $c->profesional->nombre,
                        'profesional_avatar_url' => $c->profesional->avatar_url,
                        'orden' => $c->orden,
                    ])->values(),
                ] : []);
            })
            ->values();

        return response()->json($servicios);
    }

    // ─────────────────────────────────────────────
    // GET /api/public/{slug}/disponibilidad?fecha=&asignaciones[]=
    // Slots disponibles para una fecha. `asignaciones` es la UNICA forma de
    // pedir disponibilidad (combo-multi-profesional, PR 3a3): un grupo por
    // servicio(s) + profesional elegida (o null = "Cualquiera", solo valido
    // con un unico grupo). Un grupo cuyo unico servicio es una promo
    // componentizada no lleva profesional propia (la fijan sus componentes).
    // ─────────────────────────────────────────────
    public function disponibilidad(
        Request $request,
        string $slug,
        DisponibilidadService $disponibilidad,
        PromoComponentes $promoComponentes,
    ): JsonResponse {
        $data = $request->validate($this->reglasAsignaciones() + [
            'fecha' => 'required|date_format:Y-m-d|after_or_equal:today',
        ]);

        $user = $this->getProfesional($slug);

        $resuelto = $this->resolverAsignaciones($user, $data['asignaciones'], $promoComponentes);
        if ($resuelto instanceof JsonResponse) {
            return $resuelto;
        }

        if (! $resuelto['usarPlanes']) {
            $servicios = $resuelto['servicios'];
            $slots = $disponibilidad->calcular(
                $user,
                $data['fecha'],
                $servicios,
                $resuelto['profesional'],
                Carbon::now(),
                (int) config('reservas.anticipacion_minutos', 120),
            );

            return response()->json([
                'fecha' => $data['fecha'],
                'duracion_total_minutos' => (int) $servicios->sum('duracion_minutos'),
                'slots' => $slots,
            ]);
        }

        // Plan-based (promo componentizada y/o grupos sueltos multi-profesional):
        // cada slot ya trae su propio 'fin' (ver DisponibilidadService::calcularConPlanes),
        // asi que no hay un 'duracion_total_minutos' unico y estable para todo el dia.
        $slots = $disponibilidad->calcularConPlanes(
            $user,
            $data['fecha'],
            $resuelto['promo'],
            $resuelto['gruposSueltos'],
            $promoComponentes->paraleloHabilitado($user),
            Carbon::now(),
            (int) config('reservas.anticipacion_minutos', 120),
        );

        return response()->json([
            'fecha' => $data['fecha'],
            'slots' => $slots,
        ]);
    }

    /** @return array<string, string> */
    private function reglasAsignaciones(): array
    {
        return [
            'asignaciones' => 'required|array|min:1',
            'asignaciones.*.servicio_ids' => 'required|array|min:1',
            'asignaciones.*.servicio_ids.*' => 'integer',
            'asignaciones.*.profesional_id' => 'nullable|integer',
        ];
    }

    /**
     * Resuelve los grupos de `asignaciones` en lo que necesita
     * DisponibilidadService: legacy (una profesional o "Cualquiera", un
     * unico grupo) o un PromoInput/GrupoSuelto[] para calcularConPlanes. Con
     * 2+ grupos, cada grupo SUELTO exige una profesional explicita (nunca
     * "Cualquiera"); un grupo de promo componentizada no lleva profesional y
     * por eso queda afuera de esa regla. Dos promos combinadas no estan
     * soportadas (un solo PromoInput por reserva).
     *
     * @param  array<int, array{servicio_ids: array<int,int>, profesional_id?: int|null}>  $asignaciones
     * @return array{usarPlanes: bool, servicios?: Collection, profesional?: ?Profesional, promo?: ?PromoInput, gruposSueltos?: array<int, GrupoSuelto>}|JsonResponse
     */
    private function resolverAsignaciones(User $user, array $asignaciones, PromoComponentes $promoComponentes): array|JsonResponse
    {
        $todosLosIds = array_values(array_unique(array_merge(
            ...array_map(fn (array $a) => $a['servicio_ids'], $asignaciones),
        )));
        $servicios = Servicio::where('user_id', $user->id)
            ->where('activo', true)
            ->whereIn('id', $todosLosIds)
            ->get()
            ->keyBy('id');

        if ($servicios->count() !== count($todosLosIds)) {
            return response()->json(['message' => 'Uno o más servicios no son válidos.'], 422);
        }

        $multiplesGrupos = count($asignaciones) >= 2;
        $promo = null;
        $gruposSueltos = [];
        $legacy = null;

        foreach ($asignaciones as $asignacion) {
            $ids = array_values(array_unique($asignacion['servicio_ids']));
            $grupoServicios = $servicios->only($ids)->values();
            $unico = $grupoServicios->first();

            if (count($ids) === 1 && $unico->es_promo && $unico->componentes()->exists()) {
                if ($promo !== null) {
                    return response()->json(['message' => 'No se pueden combinar dos promos en la misma reserva.'], 422);
                }
                $promo = $promoComponentes->promoInput($unico);
                continue;
            }

            $profesionalId = $asignacion['profesional_id'] ?? null;
            $profesional = null;
            if ($profesionalId !== null) {
                $profesional = Profesional::resolverParaUsuario($user, (int) $profesionalId);
                $ofrecidos = $profesional->servicios()->pluck('servicios.id')->all();

                if (count(array_diff($ids, $ofrecidos)) > 0) {
                    return response()->json(['message' => 'La profesional no ofrece todos los servicios elegidos.'], 422);
                }
            } elseif ($multiplesGrupos) {
                return response()->json(['message' => 'Elegí una profesional para cada servicio.'], 422);
            }

            $legacy = [$grupoServicios, $profesional];
            if ($profesional !== null) {
                $gruposSueltos[] = new GrupoSuelto($profesional->id, $ids, (int) $grupoServicios->sum('duracion_minutos'));
            }
        }

        if ($promo === null && count($gruposSueltos) < 2) {
            return ['usarPlanes' => false, 'servicios' => $legacy[0], 'profesional' => $legacy[1]];
        }

        return ['usarPlanes' => true, 'promo' => $promo, 'gruposSueltos' => $gruposSueltos];
    }

    // ─────────────────────────────────────────────
    // GET /api/public/{slug}/disponibilidad/dias?desde=&hasta=&asignaciones[]=
    // Dias del rango con al menos un inicio libre (y cuantos). Misma forma
    // `asignaciones` que /disponibilidad (combo-multi-profesional, PR 3a3b):
    // un unico grupo sin promo componentizada degrada byte-a-byte al conteo
    // legacy (contarLibresPorDia); con promo/2+ grupos se cuenta dia por dia
    // via calcularConPlanes (ver DisponibilidadService::contarLibresPorDiaConPlanes).
    // ─────────────────────────────────────────────
    public function disponibilidadDias(
        Request $request,
        string $slug,
        DisponibilidadService $disponibilidad,
        PromoComponentes $promoComponentes,
    ): JsonResponse {
        $data = $request->validate($this->reglasAsignaciones() + [
            'desde' => 'required|date_format:Y-m-d',
            'hasta' => 'required|date_format:Y-m-d|after_or_equal:desde',
        ]);

        $desde = Carbon::parse($data['desde'])->startOfDay();
        $hasta = Carbon::parse($data['hasta'])->startOfDay();
        if ($desde->diffInDays($hasta) + 1 > self::MAX_DIAS_RANGO) {
            return response()->json(['message' => 'El rango no puede superar '.self::MAX_DIAS_RANGO.' días.'], 422);
        }

        $user = $this->getProfesional($slug);

        $resuelto = $this->resolverAsignaciones($user, $data['asignaciones'], $promoComponentes);
        if ($resuelto instanceof JsonResponse) {
            return $resuelto;
        }

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

        $anticipacionMinutos = (int) config('reservas.anticipacion_minutos', 120);
        if (! $resuelto['usarPlanes']) {
            $libres = $disponibilidad->contarLibresPorDia(
                $user,
                $desde->format('Y-m-d'),
                $hasta->format('Y-m-d'),
                $resuelto['servicios'],
                $resuelto['profesional'],
                $ahora,
                $anticipacionMinutos,
            );
        } else {
            $libres = $disponibilidad->contarLibresPorDiaConPlanes(
                $user,
                $desde->format('Y-m-d'),
                $hasta->format('Y-m-d'),
                $resuelto['promo'],
                $resuelto['gruposSueltos'],
                $promoComponentes->paraleloHabilitado($user),
                $ahora,
                $anticipacionMinutos,
            );
        }

        $dias = [];
        foreach ($libres as $fecha => $cantidad) {
            $dias[] = ['fecha' => (string) $fecha, 'libres' => $cantidad];
        }

        return response()->json(['dias' => $dias]);
    }
}

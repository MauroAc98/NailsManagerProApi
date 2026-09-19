<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PagoSena;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use App\Services\Reservas\DisponibilidadService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PublicController extends Controller
{
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
            ->get(['id', 'nombre'])
            ->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre])
            ->values();

        return response()->json([
            'nombre'        => $user->name,
            'logo_url'      => $user->logo_url,
            'direccion'     => $user->direccion,
            'profesionales' => $profesionales,
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
            'nombre'   => $user->name,
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

        $servicios = $query->orderBy('nombre')
            ->get(['id', 'nombre', 'duracion_minutos', 'precio'])
            ->map(fn ($s) => [
                'id'               => $s->id,
                'nombre'           => $s->nombre,
                'duracion_minutos' => (int) $s->duracion_minutos,
                'precio'           => $s->precio + 0,
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
            'fecha'          => 'required|date_format:Y-m-d|after_or_equal:today',
            'servicio_ids'   => 'required|array|min:1',
            'servicio_ids.*' => 'integer',
            'profesional_id' => 'nullable|integer',
        ]);

        $user = $this->getProfesional($slug);

        $ids = array_values(array_unique($data['servicio_ids']));
        $servicios = Servicio::where('user_id', $user->id)
            ->where('activo', true)
            ->whereIn('id', $ids)
            ->get();

        if ($servicios->count() !== count($ids)) {
            return response()->json(['message' => 'Uno o más servicios no son válidos.'], 422);
        }

        $profesional = null;
        if (!empty($data['profesional_id'])) {
            // 404 si es de otro salon o esta inactiva.
            $profesional = Profesional::resolverParaUsuario($user, (int) $data['profesional_id']);
            $ofrecidos = $profesional->servicios()->pluck('servicios.id')->all();

            if (count(array_diff($ids, $ofrecidos)) > 0) {
                return response()->json(['message' => 'La profesional no ofrece todos los servicios elegidos.'], 422);
            }
        }

        $slots = $disponibilidad->calcular(
            $user,
            $data['fecha'],
            $servicios,
            $profesional,
            Carbon::now(),
            (int) config('reservas.anticipacion_minutos', 120),
            (int) config('reservas.ventana_pago_minutos', 15),
        );

        return response()->json([
            'fecha'                  => $data['fecha'],
            'duracion_total_minutos' => (int) $servicios->sum('duracion_minutos'),
            'slots'                  => $slots,
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/public/{slug}/reservas
    // Crear una reserva desde la web pública
    // ─────────────────────────────────────────────
    public function store(Request $request, string $slug): JsonResponse
    {
        $user = $this->getProfesional($slug);

        $data = $request->validate([
            'nombre_completo' => 'required|string|max:200',
            'telefono'        => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'servicio_ids'    => 'required|array|min:1',
            'servicio_ids.*'  => 'integer',
            'fecha'           => 'required|date|after_or_equal:today',
            'slot_hora'       => 'required|date_format:H:i',
        ]);

        // Verificar que los servicios pertenecen a esta profesional
        $servicios = Servicio::where('user_id', $user->id)
            ->whereIn('id', $data['servicio_ids'])
            ->where('activo', true)
            ->get();

        if ($servicios->count() !== count($data['servicio_ids'])) {
            return response()->json([
                'message' => 'Uno o más servicios no son válidos.',
            ], 422);
        }

        $duracionTotal = $servicios->sum('duracion_minutos');

        // Verificar que el slot sigue disponible
        $slotOcupado = $this->slotEstaOcupado(
            $user->id,
            $data['fecha'],
            $data['slot_hora'],
            $duracionTotal,
        );

        if ($slotOcupado) {
            return response()->json([
                'message' => 'El horario seleccionado ya no está disponible. Por favor elegí otro.',
            ], 422);
        }

        // Crear la reserva
        $reserva = ReservaWeb::create([
            'user_id'                => $user->id,
            'nombre_completo'        => $data['nombre_completo'],
            'telefono'               => $data['telefono'],
            'servicio_ids'           => $data['servicio_ids'],
            'fecha'                  => $data['fecha'],
            'slot_hora'              => $data['slot_hora'],
            'duracion_total_minutos' => $duracionTotal,
            'estado'                 => 'pending_payment',
        ]);

        // Si la profesional tiene seña configurada, crear preferencia de MP
        if ($user->sena_monto > 0 && $user->mpCredentials) {
            $pagoSena = PagoSena::create([
                'reserva_web_id'   => $reserva->id,
                'mp_preference_id' => 'pending',
                'monto'            => $user->sena_monto,
                'estado'           => 'pendiente',
            ]);

            // TODO: integrar SDK de Mercado Pago para generar la preference
            // $preference = MPService::crearPreference($user, $reserva, $pagoSena);
            // $pagoSena->update(['mp_preference_id' => $preference->id]);

            return response()->json([
                'message'        => 'Reserva creada. Completá el pago de la seña para confirmar.',
                'reserva_id'     => $reserva->id,
                'sena_monto'     => $user->sena_monto,
                'mp_preference'  => null, // ← se completa cuando integres MP
            ], 201);
        }

        // Sin seña — la reserva queda pendiente de aprobación manual
        return response()->json([
            'message'    => 'Reserva recibida. Te avisaremos cuando sea confirmada.',
            'reserva_id' => $reserva->id,
        ], 201);
    }

    // ─────────────────────────────────────────────
    // Helper privado — verifica si un slot está ocupado
    // ─────────────────────────────────────────────
    private function slotEstaOcupado(
        int $userId,
        string $fecha,
        string $slotHora,
        int $duracion,
    ): bool {
        $slotCarbon  = Carbon::parse("{$fecha} {$slotHora}");
        $slotMinutos = $slotCarbon->hour * 60 + $slotCarbon->minute;
        $slotFin     = $slotMinutos + $duracion;

        // Verificar contra turnos confirmados
        $turnoOcupado = Turno::where('user_id', $userId)
            ->confirmados()
            ->whereDate('fecha_hora', $fecha)
            ->get(['fecha_hora', 'duracion_total_minutos'])
            ->contains(function ($turno) use ($slotMinutos, $slotFin) {
                $inicio = Carbon::parse($turno->fecha_hora);
                $inicioMin = $inicio->hour * 60 + $inicio->minute;
                $finMin    = $inicioMin + $turno->duracion_total_minutos;

                return $slotMinutos < $finMin && $slotFin > $inicioMin;
            });

        if ($turnoOcupado) return true;

        // Verificar contra reservas pendientes
        return ReservaWeb::where('user_id', $userId)
            ->pendientes()
            ->where('fecha', $fecha)
            ->get(['slot_hora', 'duracion_total_minutos'])
            ->contains(function ($reserva) use ($slotMinutos, $slotFin) {
                $inicio    = Carbon::parse($reserva->slot_hora);
                $inicioMin = $inicio->hour * 60 + $inicio->minute;
                $finMin    = $inicioMin + $reserva->duracion_total_minutos;

                return $slotMinutos < $finMin && $slotFin > $inicioMin;
            });
    }
}
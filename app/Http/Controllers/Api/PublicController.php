<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PagoSena;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
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

        if (!$user->activo) {
            abort(403, 'Esta agenda no está disponible.');
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

        return response()->json([
            'name'      => $user->name,
            'telefono'  => $user->telefono,
            'direccion' => $user->direccion,
            'slug'      => $user->slug,
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/public/{slug}/servicios
    // Lista de servicios activos del estudio
    // ─────────────────────────────────────────────
    public function servicios(string $slug): JsonResponse
    {
        $user = $this->getProfesional($slug);

        $servicios = Servicio::where('user_id', $user->id)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'duracion_minutos', 'precio']);

        return response()->json($servicios);
    }

    // ─────────────────────────────────────────────
    // GET /api/public/{slug}/disponibilidad?fecha=
    // Slots disponibles para una fecha
    // ─────────────────────────────────────────────
    public function disponibilidad(Request $request, string $slug): JsonResponse
    {
        $request->validate([
            'fecha' => 'required|date|after_or_equal:today',
        ]);

        $user  = $this->getProfesional($slug);
        $fecha = $request->fecha;
        $ahora = Carbon::now();
        $esHoy = $fecha === $ahora->toDateString();

        // Slots configurados por la profesional
        $slots = $user->slotsDisponibles()->activos()->orderBy('hora')->get();

        // Turnos confirmados del día
        $turnos = Turno::where('user_id', $user->id)
            ->confirmados()
            ->whereDate('fecha_hora', $fecha)
            ->get(['fecha_hora', 'duracion_total_minutos']);

        // Reservas pendientes del día
        $reservas = ReservaWeb::where('user_id', $user->id)
            ->pendientes()
            ->where('fecha', $fecha)
            ->get(['slot_hora', 'duracion_total_minutos']);

        $resultado = $slots->map(function ($slot) use ($turnos, $reservas, $esHoy, $ahora) {
            $slotCarbon  = Carbon::parse($slot->hora);
            $slotMinutos = $slotCarbon->hour * 60 + $slotCarbon->minute;

            // Bloqueo por hora pasada
            if ($esHoy && $slotCarbon->hour <= $ahora->hour) {
                return ['hora' => $slotCarbon->format('H:i'), 'libre' => false];
            }

            // Bloqueo por turno confirmado
            foreach ($turnos as $turno) {
                $inicio = Carbon::parse($turno->fecha_hora);
                $inicioMin = $inicio->hour * 60 + $inicio->minute;
                $finMin    = $inicioMin + $turno->duracion_total_minutos;

                if ($slotMinutos >= $inicioMin && $slotMinutos < $finMin) {
                    return ['hora' => $slotCarbon->format('H:i'), 'libre' => false];
                }
            }

            // Bloqueo por reserva pendiente
            foreach ($reservas as $reserva) {
                $inicioReserva = Carbon::parse($reserva->slot_hora);
                $inicioMin     = $inicioReserva->hour * 60 + $inicioReserva->minute;
                $finMin        = $inicioMin + $reserva->duracion_total_minutos;

                if ($slotMinutos >= $inicioMin && $slotMinutos < $finMin) {
                    return ['hora' => $slotCarbon->format('H:i'), 'libre' => false];
                }
            }

            return ['hora' => $slotCarbon->format('H:i'), 'libre' => true];
        });

        return response()->json($resultado->values());
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
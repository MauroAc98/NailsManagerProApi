<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\PagoSena;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\Turno;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ReservaWebController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/reservas
    // Panel de reservas pendientes en la app
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $reservas = ReservaWeb::delUsuario($request->user())
            ->pendientes()
            ->with('pagoSena')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($reserva) {
                // Enriquecer con nombres de servicios
                $servicios = Servicio::whereIn('id', $reserva->servicio_ids)->get(['id', 'nombre', 'duracion_minutos']);
                $reserva->servicios = $servicios;
                return $reserva;
            });

        return response()->json($reservas);
    }

    // ─────────────────────────────────────────────
    // POST /api/reservas/{id}/aceptar
    // ─────────────────────────────────────────────
    public function aceptar(Request $request, int $id): JsonResponse
    {
        $user    = $request->user();
        $reserva = ReservaWeb::delUsuario($user)->pendientes()->findOrFail($id);

        DB::transaction(function () use ($reserva, $user) {
            // Buscar o crear la clienta por teléfono
            $cliente = Cliente::delUsuario($user)
                ->where('telefono', $reserva->telefono)
                ->first();

            if (!$cliente) {
                $cliente = $user->clientes()->create([
                    'nombre_completo' => $reserva->nombre_completo,
                    'telefono'        => $reserva->telefono,
                ]);
            }

            // Crear el turno
            $turno = Turno::create([
                'user_id'                => $user->id,
                'cliente_id'             => null,
                'reserva_web_id'         => $reserva->id,
                'fecha_hora'             => $reserva->fecha . ' ' . $reserva->slot_hora,
                'duracion_total_minutos' => $reserva->duracion_total_minutos,
                'estado'                 => 'confirmado',
                'origen'                 => 'web',
            ]);

            // Asociar servicios al turno
            $turno->servicios()->attach($reserva->servicio_ids);

            // Actualizar estado de la reserva
            $reserva->update(['estado' => 'accepted']);
        });

        return response()->json([
            'message' => 'Reserva aceptada correctamente.',
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/reservas/{id}/rechazar
    // ─────────────────────────────────────────────
    public function rechazar(Request $request, int $id): JsonResponse
    {
        $user    = $request->user();
        $reserva = ReservaWeb::delUsuario($user)->pendientes()->findOrFail($id);

        $reserva->update(['estado' => 'rejected']);

        return response()->json([
            'message' => 'Reserva rechazada correctamente.',
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/webhooks/mercadopago
    // Webhook de Mercado Pago — sin auth, con firma HMAC
    // ─────────────────────────────────────────────
    public function webhookMercadoPago(Request $request): JsonResponse
    {
        // Verificar firma HMAC de Mercado Pago
        $signature = $request->header('x-signature');
        $requestId = $request->header('x-request-id');

        if (!$signature || !$requestId) {
            return response()->json(['message' => 'Firma inválida.'], 401);
        }

        $dataId  = $request->query('data_id');
        $secret  = config('services.mercadopago.webhook_secret');
        $manifest = "id:{$dataId};request-id:{$requestId};";

        $hash = hash_hmac('sha256', $manifest, $secret);

        // Extraer ts y v1 del header x-signature
        $parts = collect(explode(',', $signature))
            ->mapWithKeys(function ($part) {
                [$key, $value] = explode('=', trim($part));
                return [$key => $value];
            });

        if (!hash_equals($hash, $parts->get('v1', ''))) {
            return response()->json(['message' => 'Firma inválida.'], 401);
        }

        // Procesar solo notificaciones de pago
        if ($request->input('type') !== 'payment') {
            return response()->json(['message' => 'OK']);
        }

        $mpPaymentId = $request->input('data.id');

        // Idempotencia — si ya procesamos este pago, ignoramos
        $pagoExistente = PagoSena::where('mp_payment_id', $mpPaymentId)->first();
        if ($pagoExistente?->estaAprobado()) {
            return response()->json(['message' => 'OK']);
        }

        // Buscar el pago por preference_id
        $preferenceId = $request->input('data.metadata.preference_id');
        $pago = PagoSena::where('mp_preference_id', $preferenceId)->first();

        if (!$pago) {
            return response()->json(['message' => 'OK']);
        }

        $estado = $request->input('data.status');

        DB::transaction(function () use ($pago, $mpPaymentId, $estado) {
            $pago->update([
                'mp_payment_id' => $mpPaymentId,
                'estado'        => match($estado) {
                    'approved' => 'aprobado',
                    'rejected' => 'rechazado',
                    default    => 'pendiente',
                },
            ]);

            // Si el pago fue aprobado, actualizamos la reserva
            if ($estado === 'approved') {
                $pago->reservaWeb->update(['estado' => 'pending_payment']);
                // pending_payment → la profesional todavía tiene que aceptar
                // el slot queda bloqueado hasta que acepte o rechace
            }
        });

        return response()->json(['message' => 'OK']);
    }
}
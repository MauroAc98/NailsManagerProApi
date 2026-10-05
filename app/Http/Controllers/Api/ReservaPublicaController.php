<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ReservaPublicaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CrearHoldRequest;
use App\Http\Requests\GuardarDatosReservaRequest;
use App\Models\PagoSena;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\User;
use App\Services\Reservas\ChallengeVerifier;
use App\Services\Reservas\HoldService;
use App\Services\Reservas\MercadoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Escrituras de la reserva online (hold -> datos -> pago -> estado), detras de
 * los middlewares reservas.creacion + reservas.device. Las URLs usan un token
 * opaco (nunca ids); un token ajeno o inexistente es siempre 404.
 */
class ReservaPublicaController extends Controller
{
    public function __construct(
        private HoldService $holds,
        private ChallengeVerifier $challenge,
        private MercadoPagoService $mercadoPago,
    ) {
    }

    // POST /api/public/{slug}/reservas/holds
    public function hold(CrearHoldRequest $request, string $slug): JsonResponse
    {
        $user = $this->salon($slug);
        $deviceHash = $this->deviceHash($request);
        $key = (string) $request->input('idempotency_key');

        // Un reintento de red con la misma key devuelve el estado actual SIN
        // volver a pedir el reto (el token de Turnstile es de un solo uso).
        $previa = $this->holds->buscarReplay($user, $deviceHash, $key);
        if ($previa) {
            return response()->json($this->payloadHold($previa), 200);
        }

        if (! $this->challenge->verificar($request->input('challenge_token'), (string) $request->ip())) {
            Log::info('reserva.abuse.challenge_failed', [
                'user_id' => $user->id,
                'device_prefix' => substr($deviceHash, 0, 8),
                'motivo' => 'challenge_failed',
            ]);
            throw ReservaPublicaException::challengeFailed();
        }

        $resultado = $this->holds->retener(
            $user,
            $request->input('asignaciones'),
            $request->input('modo'),
            $request->input('fecha'),
            $request->input('hora'),
            $deviceHash,
            $key,
            Carbon::now(),
        );

        return response()->json($this->payloadHold($resultado->reserva), $resultado->replay ? 200 : 201);
    }

    // PUT /api/public/{slug}/reservas/{token}/datos
    public function datos(GuardarDatosReservaRequest $request, string $slug, string $token): JsonResponse
    {
        $user = $this->salon($slug);

        $reserva = $this->holds->guardarDatos(
            $user,
            $this->token($token),
            trim($request->input('nombre')),
            trim($request->input('apellido')),
            $request->input('whatsapp'),
            $request->filled('nota') ? trim($request->input('nota')) : null,
            Carbon::now(),
        )->reserva;

        return response()->json($this->payloadBasico($reserva));
    }

    // POST /api/public/{slug}/reservas/{token}/pago
    public function pago(Request $request, string $slug, string $token): JsonResponse
    {
        $user = $this->salon($slug);
        $reserva = $this->holds->iniciarPago($user, $this->token($token), Carbon::now())->reserva;
        $pago = $this->mercadoPago->crearOReusarPreferencia($user, $reserva);

        return response()->json($this->payloadBasico($reserva) + [
            'checkout_url' => $pago->init_point,
        ]);
    }

    // DELETE /api/public/{slug}/reservas/{token}
    public function destroy(Request $request, string $slug, string $token): Response
    {
        $this->holds->liberar($this->salon($slug), $this->token($token), Carbon::now());

        return response()->noContent();
    }

    // GET /api/public/{slug}/reservas/{token}
    public function show(Request $request, string $slug, string $token): JsonResponse
    {
        $user = $this->salon($slug);
        $reserva = $this->holds->estado($user, $this->token($token), Carbon::now());
        $estado = $this->estadoPublico($reserva);

        $payload = $this->payloadBasico($reserva);
        if ($estado === 'pending_payment') {
            $payload['checkout_url'] = $this->checkoutUrl($reserva);
        }
        $payload['resumen'] = [
            'servicio_ids' => array_map('intval', $reserva->servicio_ids ?? []),
            'profesional_id' => $reserva->profesional_id,
            'fecha' => $this->fecha($reserva),
            'hora' => $this->hora($reserva),
            'duracion_total_minutos' => (int) $reserva->duracion_total_minutos,
            // Con un PagoSena ya creado, el monto congelado de ese cobro; si
            // no, la seña sobre el precio congelado del hold. Es lo que se cobra.
            'deposito' => ($d = $this->mercadoPago->depositoParaReserva($user, $reserva)) === null ? null : $this->monto($d),
            'nota' => $reserva->nota,
        ];
        if ($reserva->tramos !== null) {
            $payload['resumen'] += $this->extraTramos($reserva) + [
                'profesionales' => $this->profesionalesDe($reserva),
            ];
        }

        return response()->json($payload);
    }

    // ── helpers ────────────────────────────────────────────

    /** Salon por slug; 404 (no 403) tambien con suscripcion vencida/suspendida. */
    private function salon(string $slug): User
    {
        $user = User::where('slug', $slug)->first();
        if (! $user || $user->suscripcionVencida()) {
            throw ReservaPublicaException::notFound();
        }

        return $user;
    }

    /** Corta antes de tocar la DB si el token no tiene el formato emitido (ids, basura...). */
    private function token(string $token): string
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            throw ReservaPublicaException::notFound();
        }

        return $token;
    }

    private function deviceHash(Request $request): string
    {
        return (string) $request->attributes->get('device_hash');
    }

    /** Estado hacia afuera: held | pending_payment | confirmed | expired | cancelled. */
    private function estadoPublico(ReservaWeb $r): string
    {
        return match ($r->estado) {
            'accepted' => 'confirmed',
            'rejected' => 'cancelled',
            default => $r->estado,
        };
    }

    private function payloadBasico(ReservaWeb $r): array
    {
        return [
            'token' => $r->public_token,
            'estado' => $this->estadoPublico($r),
            'expira_en_ms' => (int) $r->expira_en * 1000,
        ];
    }

    /**
     * combo-multi-profesional (PR 3b): un hold de un unico tramo (legacy)
     * mantiene el contrato exacto de siempre. Un hold multi-tramo (tramos no
     * nulo) suma `fin` y `tramos` (profesional_id/hora/fin/servicio_ids de
     * cada uno) — `profesional_id` arriba sigue siendo la ancla.
     */
    private function payloadHold(ReservaWeb $r): array
    {
        $base = $this->payloadBasico($r) + [
            'profesional_id' => $r->profesional_id,
            'fecha' => $this->fecha($r),
            'hora' => $this->hora($r),
            'duracion_total_minutos' => (int) $r->duracion_total_minutos,
        ];

        if ($r->tramos === null) {
            return $base;
        }

        return $base + $this->extraTramos($r);
    }

    /** `fin` y `tramos` de una reserva multi-tramo (horas de pared, sin casts de datetime). */
    private function extraTramos(ReservaWeb $r): array
    {
        $inicio = Carbon::parse($this->fecha($r) . ' ' . $this->hora($r));

        return [
            'fin' => $inicio->copy()->addMinutes((int) $r->duracion_total_minutos)->format('H:i'),
            'tramos' => array_map(fn (array $t) => [
                'profesional_id' => $t['profesional_id'],
                'hora' => $inicio->copy()->addMinutes($t['offset_minutos'])->format('H:i'),
                'fin' => $inicio->copy()->addMinutes($t['offset_minutos'] + $t['duracion_minutos'])->format('H:i'),
                'servicio_ids' => $t['servicio_ids'],
            ], $r->tramos),
        ];
    }

    /** Profesionales distintas del grupo, en orden de tramo (para "con Ana y Laura"). */
    private function profesionalesDe(ReservaWeb $r): array
    {
        $ids = array_values(array_unique(array_column($r->tramos, 'profesional_id')));
        $nombres = Profesional::whereIn('id', $ids)->pluck('nombre', 'id');

        return array_map(fn (int $id) => ['id' => $id, 'nombre' => $nombres[$id] ?? ''], $ids);
    }

    private function fecha(ReservaWeb $r): string
    {
        return substr((string) $r->getRawOriginal('fecha'), 0, 10);
    }

    private function hora(ReservaWeb $r): string
    {
        return substr((string) $r->getRawOriginal('slot_hora'), 0, 5);
    }

    /** 5000.00 -> 5000 (int) y 1500.5 -> 1500.5, como el resto de los montos publicos. */
    private function monto(mixed $valor): int|float
    {
        $m = (float) ($valor ?? 0);

        return $m == floor($m) ? (int) $m : $m;
    }

    /** Link de la preferencia de MP ya creada para esta reserva (ver MercadoPagoService). */
    private function checkoutUrl(ReservaWeb $r): ?string
    {
        return PagoSena::where('reserva_web_id', $r->id)
            ->whereIn('estado', ['pendiente', 'aprobado'])
            ->latest('id')
            ->value('init_point');
    }
}

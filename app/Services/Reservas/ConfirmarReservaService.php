<?php

namespace App\Services\Reservas;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Cliente;
use App\Models\ReservaWeb;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Convierte una reserva web pagada en un Turno confirmado. Lo llamara el
 * webhook de pago (slice de Mercado Pago). Idempotente y sin doble reserva:
 * corre bajo el lock de la profesional y re-chequea la agenda, porque la
 * duena pudo agendar encima del hold (o el pago llego tarde, ya vencido).
 */
class ConfirmarReservaService
{
    public function __construct(
        private SlotLock $lock,
        private DisponibilidadService $disponibilidad,
    ) {
    }

    public function confirmar(ReservaWeb $reserva, Carbon $ahora): ConfirmacionResultado
    {
        // Sin profesional (fila legacy) no hay agenda que resolver.
        if ($reserva->profesional_id === null) {
            return $this->needsRefund($reserva, 'sin_profesional', $ahora);
        }

        return $this->lock->conLock($reserva->profesional_id, function () use ($reserva, $ahora) {
            $r = ReservaWeb::whereKey($reserva->id)->lockForUpdate()->firstOrFail();

            if ($r->estado === 'confirmed') {
                return new ConfirmacionResultado(
                    ConfirmacionResultado::ALREADY_CONFIRMED,
                    Turno::where('reserva_web_id', $r->id)->first(),
                );
            }

            if (! $r->nombre || ! $r->telefono) {
                return $this->needsRefund($r, 'datos_incompletos', $ahora);
            }

            $vivo = in_array($r->estado, ReservaWeb::ESTADOS_BLOQUEANTES, true)
                && $r->expira_en !== null && $r->expira_en > $ahora->timestamp;

            // Vivo: la duena pudo agendar encima. Tardio (vencido/cancelado): ademas otro
            // hold vivo pudo tomar el horario. Ambos casos = misma verificacion contra la
            // agenda ignorando esta reserva; el lead time no aplica (ya pago).
            $libre = $this->disponibilidad->estaLibre(
                $r->profesional_id,
                substr((string) $r->getRawOriginal('fecha'), 0, 10),
                (string) $r->getRawOriginal('slot_hora'),
                (int) $r->duracion_total_minutos,
                $ahora,
                $r->id,
                null,
            );
            if (! $libre) {
                return $this->needsRefund($r, $vivo ? 'slot_conflict' : 'slot_taken_after_expiry', $ahora);
            }

            $turno = $this->crearTurno($r);
            $r->update(['estado' => 'confirmed', 'confirmada_en' => $ahora->timestamp, 'motivo_cierre' => null]);

            DB::afterCommit(fn () => EnviarMensajeConfirmacion::dispatch($turno->id));

            Log::info('reserva.confirmed', [
                'user_id' => $r->user_id,
                'profesional_id' => $r->profesional_id,
                'reserva_id' => $r->id,
                'device_prefix' => $r->device_hash ? substr($r->device_hash, 0, 8) : null,
                'motivo' => $vivo ? null : 'pago_tardio',
            ]);

            return new ConfirmacionResultado(ConfirmacionResultado::CONFIRMED, $turno);
        });
    }

    private function crearTurno(ReservaWeb $r): Turno
    {
        $user = User::findOrFail($r->user_id);
        $cliente = Cliente::todosPorTelefono($user, (string) $r->telefono)->first()
            ?? $user->clientes()->create([
                'nombre' => $r->nombre,
                'apellido' => $r->apellido,
                'telefono' => $r->telefono,
            ]);

        $turno = Turno::create([
            'user_id' => $r->user_id,
            'profesional_id' => $r->profesional_id,
            'cliente_id' => $cliente->id,
            'reserva_web_id' => $r->id,
            'fecha_hora' => substr((string) $r->getRawOriginal('fecha'), 0, 10) . ' ' . $r->getRawOriginal('slot_hora'),
            'duracion_total_minutos' => $r->duracion_total_minutos,
            'estado' => 'confirmado',
            'origen' => 'web',
            'notas' => $r->nota,
        ]);
        $turno->servicios()->attach($r->servicio_ids);

        return $turno;
    }

    /** Marca la reserva para reembolso; si seguia viva la cierra (expired) para liberar el horario. */
    private function needsRefund(ReservaWeb $r, string $motivo, Carbon $ahora): ConfirmacionResultado
    {
        $cambios = ['requiere_reembolso' => true];
        if (in_array($r->estado, ReservaWeb::ESTADOS_BLOQUEANTES, true)) {
            $cambios += ['estado' => 'expired', 'motivo_cierre' => 'reembolso'];
        }
        $r->update($cambios);

        Log::info('reserva.needs_refund', [
            'user_id' => $r->user_id,
            'profesional_id' => $r->profesional_id,
            'reserva_id' => $r->id,
            'device_prefix' => $r->device_hash ? substr($r->device_hash, 0, 8) : null,
            'motivo' => $motivo,
        ]);

        return new ConfirmacionResultado(ConfirmacionResultado::NEEDS_REFUND, null, $motivo);
    }
}

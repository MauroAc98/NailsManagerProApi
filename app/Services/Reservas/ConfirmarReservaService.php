<?php

namespace App\Services\Reservas;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Jobs\EnviarPushReembolsoReserva;
use App\Jobs\EnviarPushReservaOnline;
use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Turno;
use App\Models\TurnoGrupo;
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

        // combo-multi-profesional (PR 3c): reserva con tramos (promo componentizada
        // o grupos sueltos multi-profesional) sigue un camino aparte; el legacy de
        // abajo queda byte-identico (Rule L).
        if ($reserva->tramos !== null) {
            return $this->confirmarGrupo($reserva, $ahora);
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
            $this->notificarPush($turno);

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

    /**
     * Web Push to the salon's devices: one per confirmed online reservation (the
     * grupo leader stands for the whole group). Best-effort: a failure to even
     * enqueue it must never undo or fail the booking.
     */
    private function notificarPush(Turno $turno): void
    {
        DB::afterCommit(function () use ($turno) {
            try {
                EnviarPushReservaOnline::dispatch($turno->id);
            } catch (\Throwable $e) {
                Log::warning('reserva.push_dispatch_failed', ['turno_id' => $turno->id, 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Web Push to the owner: the client PAID but got no turno, so they must refund
     * from Mercado Pago. Best-effort: never breaks the confirmation flow.
     */
    private function notificarReembolso(ReservaWeb $r): void
    {
        $reservaId = $r->id;
        DB::afterCommit(function () use ($reservaId) {
            try {
                EnviarPushReembolsoReserva::dispatch($reservaId);
            } catch (\Throwable $e) {
                Log::warning('reserva.refund_push_dispatch_failed', ['reserva_id' => $reservaId, 'error' => $e->getMessage()]);
            }
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

    /**
     * combo-multi-profesional (PR 3c): reserva con `tramos` (promo componentizada
     * y/o grupos sueltos multi-profesional). Lockea TODAS las profesionales del
     * plan (SlotLock::conLocks, mismo orden que HoldService::intentarPlan) y
     * re-valida CADA tramo (alineacion + libre) antes de confirmar: la promo
     * pudo desconfigurarse o la duena pudo agendar encima de un tramo desde el
     * hold. Sin re-precio: usa el precio_sugerido ya prorrateado en el hold
     * (spec: "no auto-repricing").
     */
    private function confirmarGrupo(ReservaWeb $reserva, Carbon $ahora): ConfirmacionResultado
    {
        $profesionalIds = array_values(array_unique(array_column($reserva->tramos, 'profesional_id')));

        return $this->lock->conLocks($profesionalIds, function () use ($reserva, $ahora) {
            $r = ReservaWeb::whereKey($reserva->id)->lockForUpdate()->firstOrFail();

            if ($r->estado === 'confirmed') {
                return new ConfirmacionResultado(
                    ConfirmacionResultado::ALREADY_CONFIRMED,
                    Turno::where('reserva_web_id', $r->id)->orderBy('id')->first(),
                );
            }

            if (! $r->nombre || ! $r->telefono) {
                return $this->needsRefund($r, 'datos_incompletos', $ahora);
            }

            if (! $this->grupoSigueValido($r, $ahora)) {
                return $this->needsRefund($r, 'slot_desalineado', $ahora);
            }

            $turno = $this->crearGrupo($r);
            $r->update(['estado' => 'confirmed', 'confirmada_en' => $ahora->timestamp, 'motivo_cierre' => null]);

            DB::afterCommit(fn () => EnviarMensajeConfirmacion::dispatch($turno->id));
            $this->notificarPush($turno);

            Log::info('reserva.confirmed', [
                'user_id' => $r->user_id,
                'profesional_id' => $r->profesional_id,
                'reserva_id' => $r->id,
                'device_prefix' => $r->device_hash ? substr($r->device_hash, 0, 8) : null,
                'motivo' => null,
            ]);

            return new ConfirmacionResultado(ConfirmacionResultado::CONFIRMED, $turno);
        });
    }

    /**
     * Cada tramo (ya prorrateado y persistido en el hold) sigue alineado a un
     * slot activo de su propia profesional (AlineacionSlots) y sigue libre en
     * su propio horario (estaLibre, ignorando esta misma reserva). No recalcula
     * el plan con TramosResolver: solo re-chequea agenda, nunca re-precia.
     */
    private function grupoSigueValido(ReservaWeb $r, Carbon $ahora): bool
    {
        $fecha = substr((string) $r->getRawOriginal('fecha'), 0, 10);
        $hora = substr((string) $r->getRawOriginal('slot_hora'), 0, 5);
        $user = User::findOrFail($r->user_id);

        $profesionales = Profesional::whereIn('id', array_column($r->tramos, 'profesional_id'))->get();
        $slotsPorProfesional = $profesionales->mapWithKeys(
            fn (Profesional $p) => [$p->id => $this->disponibilidad->horasActivas($user, $p)],
        )->all();
        if ((new AlineacionSlots())->primerDesalineado($r->tramos, $hora, $slotsPorProfesional) !== null) {
            return false;
        }

        $inicio = Carbon::parse("{$fecha} {$hora}");
        foreach ($r->tramos as $tramo) {
            $inicioTramo = $inicio->copy()->addMinutes((int) $tramo['offset_minutos']);
            $libre = $this->disponibilidad->estaLibre(
                (int) $tramo['profesional_id'], $fecha, $inicioTramo->format('H:i'),
                (int) $tramo['duracion_minutos'], $ahora, $r->id, null,
            );
            if (! $libre) {
                return false;
            }
        }

        return true;
    }

    /** Crea turno_grupos + UN Turno por tramo, todos ligados; devuelve el lider (primer tramo = ancla). */
    private function crearGrupo(ReservaWeb $r): Turno
    {
        $user = User::findOrFail($r->user_id);
        $cliente = Cliente::todosPorTelefono($user, (string) $r->telefono)->first()
            ?? $user->clientes()->create([
                'nombre' => $r->nombre,
                'apellido' => $r->apellido,
                'telefono' => $r->telefono,
            ]);

        $grupo = TurnoGrupo::create(['reserva_web_id' => $r->id, 'modo' => $r->tramos_modo, 'promo_servicio_id' => $r->promo_servicio_id]);

        $fecha = substr((string) $r->getRawOriginal('fecha'), 0, 10);
        $inicio = Carbon::parse($fecha . ' ' . $r->getRawOriginal('slot_hora'));
        $lider = null;

        foreach ($r->tramos as $tramo) {
            $turno = Turno::create([
                'user_id' => $r->user_id,
                'profesional_id' => $tramo['profesional_id'],
                'cliente_id' => $cliente->id,
                'reserva_web_id' => $r->id,
                'grupo_id' => $grupo->id,
                'fecha_hora' => $inicio->copy()->addMinutes((int) $tramo['offset_minutos'])->format('Y-m-d H:i:s'),
                'duracion_total_minutos' => $tramo['duracion_minutos'],
                'estado' => 'confirmado',
                'origen' => 'web',
                'notas' => $r->nota,
            ]);
            $turno->servicios()->attach($this->pivotServicios($tramo['servicio_ids'], $tramo['precio_sugerido'] ?? null));
            $lider ??= $turno;
        }

        return $lider;
    }

    /** Reparte precio_sugerido (entero, ya prorrateado) entre los servicios de un tramo fusionado; null si el tramo no tiene precio sugerido. */
    public static function pivotServicios(array $servicioIds, ?int $precioSugerido): array
    {
        $n = count($servicioIds);
        if ($precioSugerido === null || $n === 1) {
            return collect($servicioIds)->mapWithKeys(fn ($id) => [$id => ['precio_sugerido' => $precioSugerido]])->all();
        }

        $base = intdiv($precioSugerido, $n);
        $pivote = [];
        foreach (array_values($servicioIds) as $i => $id) {
            $pivote[$id] = ['precio_sugerido' => $i < $n - 1 ? $base : $precioSugerido - $base * ($n - 1)];
        }

        return $pivote;
    }

    /** Marca la reserva para reembolso; si seguia viva la cierra (expired) para liberar el horario. */
    private function needsRefund(ReservaWeb $r, string $motivo, Carbon $ahora): ConfirmacionResultado
    {
        $cambios = ['requiere_reembolso' => true];
        if (in_array($r->estado, ReservaWeb::ESTADOS_BLOQUEANTES, true)) {
            $cambios += ['estado' => 'expired', 'motivo_cierre' => 'reembolso'];
        }
        $r->update($cambios);

        $this->notificarReembolso($r);

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

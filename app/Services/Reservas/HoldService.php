<?php

namespace App\Services\Reservas;

use App\Exceptions\ReservaPublicaException;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Ciclo de vida de un hold de reserva online (reservas_web). Toda decision
 * sobre la agenda de una profesional corre dentro de SlotLock, y el indice
 * unico parcial (profesional, fecha, hora) es la ultima linea de defensa.
 */
class HoldService
{
    /**
     * Seam de test: se invoca (con el profesional_id) despues del re-check y
     * antes del insert, para simular una carrera sin threads.
     *
     * @var (Closure(int): void)|null
     */
    public ?Closure $despuesDeVerificar = null;

    public function __construct(
        private SlotLock $lock,
        private DisponibilidadService $disponibilidad,
        private PoliticaHold $politica,
        private ExpirarHoldsService $expirar,
    ) {
    }

    /**
     * @param  array<int, int>  $servicioIds
     * @throws ReservaPublicaException validation 422 | slot_taken 409
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException profesional ajena o inactiva (404)
     */
    public function retener(
        User $user,
        array $servicioIds,
        ?int $profesionalId,
        string $fecha,
        string $hora,
        string $deviceHash,
        string $idempotencyKey,
        Carbon $ahora,
    ): HoldResultado {
        $replay = ReservaWeb::where('user_id', $user->id)
            ->where('device_hash', $deviceHash)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($replay) {
            return new HoldResultado($replay, true);
        }

        // Ordena los vencidos de este dispositivo (la disponibilidad ya los ignora por expira_en).
        $this->expirar->expirarVencidos($ahora->timestamp, $deviceHash);

        $hora = Carbon::parse($hora)->format('H:i');
        [$servicios, $candidatas] = $this->resolver($user, $servicioIds, $profesionalId);
        $duracion = (int) $servicios->sum('duracion_minutos');

        // Solo profesionales para las que la hora es un slot activo configurado.
        $candidatas = $candidatas->filter(fn (Profesional $p) => in_array($hora, $this->disponibilidad->horasActivas($user, $p), true))->values();
        if ($candidatas->isEmpty()) {
            throw ReservaPublicaException::validacion('El horario elegido no es válido.');
        }

        foreach ($candidatas as $profesional) {
            try {
                $reserva = $this->lock->conLock($profesional->id, fn () => $this->intentar(
                    $user, $profesional, $servicios->pluck('id')->all(), $duracion, $fecha, $hora, $deviceHash, $idempotencyKey, $ahora,
                ));
            } catch (SlotNoDisponible) {
                continue;
            } catch (QueryException $e) {
                // Carrera por idempotency_key (mismo dispositivo, dos requests a la vez): replay.
                $previa = ReservaWeb::where('user_id', $user->id)->where('device_hash', $deviceHash)->where('idempotency_key', $idempotencyKey)->first();
                if ($previa) {
                    return new HoldResultado($previa, true);
                }
                throw $e;
            }

            Log::info('reserva.hold.created', $this->contexto($reserva) + ['motivo' => $reserva->alta_ocupacion ? 'alta_ocupacion' : null]);

            return new HoldResultado($reserva);
        }

        Log::info('reserva.hold.slot_taken', [
            'user_id' => $user->id,
            'profesional_id' => $profesionalId,
            'reserva_id' => null,
            'device_prefix' => substr($deviceHash, 0, 8),
            'motivo' => 'slot_taken',
        ]);

        throw ReservaPublicaException::slotTaken();
    }

    /**
     * @return array{0: Collection<int, Servicio>, 1: Collection<int, Profesional>}
     */
    private function resolver(User $user, array $servicioIds, ?int $profesionalId): array
    {
        $ids = array_values(array_unique(array_map('intval', $servicioIds)));
        $servicios = Servicio::where('user_id', $user->id)->where('activo', true)->whereIn('id', $ids)->get();
        if ($ids === [] || $servicios->count() !== count($ids)) {
            throw ReservaPublicaException::validacion('Uno o más servicios no son válidos.');
        }

        $profesional = $profesionalId ? Profesional::resolverParaUsuario($user, $profesionalId) : null;
        $candidatas = $this->disponibilidad->profesionalesCandidatas($user, $profesional, $ids);
        if ($candidatas->isEmpty()) {
            throw ReservaPublicaException::validacion($profesional
                ? 'La profesional no ofrece todos los servicios elegidos.'
                : 'Ninguna profesional ofrece todos los servicios elegidos.');
        }

        return [$servicios, $candidatas];
    }

    /** Corre DENTRO de SlotLock (transaccion abierta). Lanza SlotNoDisponible para revertir. */
    private function intentar(
        User $user,
        Profesional $profesional,
        array $servicioIds,
        int $duracion,
        string $fecha,
        string $hora,
        string $deviceHash,
        string $idempotencyKey,
        Carbon $ahora,
    ): ReservaWeb {
        // 1) Un hold vivo por dispositivo: libera el anterior (se revierte si esto falla).
        $liberadas = ReservaWeb::where('user_id', $user->id)
            ->where('device_hash', $deviceHash)
            ->bloqueantes()
            ->update(['estado' => 'cancelled', 'motivo_cierre' => 'reemplazada', 'updated_at' => now()]);
        if ($liberadas > 0) {
            Log::info('reserva.hold.released', [
                'user_id' => $user->id,
                'profesional_id' => $profesional->id,
                'reserva_id' => null,
                'device_prefix' => substr($deviceHash, 0, 8),
                'motivo' => 'reemplazada',
            ]);
        }

        // 2) Re-check bajo lock: turnos confirmados + holds vivos de ESTA profesional + lead time.
        $anticipacion = (int) config('reservas.anticipacion_minutos', 120);
        if (! $this->disponibilidad->estaLibre($profesional->id, $fecha, $hora, $duracion, $ahora, null, $anticipacion)) {
            throw new SlotNoDisponible();
        }

        // 3) Ordena filas vencidas en este mismo inicio para que no choquen con el indice unico.
        ReservaWeb::where('profesional_id', $profesional->id)
            ->where('fecha', $fecha)
            ->where('slot_hora', $hora . ':00')
            ->bloqueantes()
            ->where('expira_en', '<=', $ahora->timestamp)
            ->get()
            ->each(fn (ReservaWeb $vieja) => $this->expirar->expirarUna($vieja, $ahora->timestamp));

        // 4) Alta ocupacion (snapshot) y duracion del hold.
        $alta = $this->politica->esAlta(
            $fecha,
            $this->disponibilidad->horasActivas($user, $profesional),
            $this->disponibilidad->ocupacionDelDia($profesional->id, $fecha, $ahora),
            $ahora,
        );

        if ($this->despuesDeVerificar) {
            ($this->despuesDeVerificar)($profesional->id);
        }

        try {
            return ReservaWeb::create([
                'user_id' => $user->id,
                'profesional_id' => $profesional->id,
                'public_token' => ReservaWeb::generarToken(),
                'servicio_ids' => $servicioIds,
                'fecha' => $fecha,
                'slot_hora' => $hora . ':00',
                'duracion_total_minutos' => $duracion,
                'estado' => 'held',
                'expira_en' => $ahora->timestamp + $this->politica->holdMinutos($alta) * 60,
                'alta_ocupacion' => $alta,
                'device_hash' => $deviceHash,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (QueryException $e) {
            if ($this->esViolacionDeUnicoDeSlot($e)) {
                throw new SlotNoDisponible();
            }
            throw $e;
        }
    }

    /**
     * Violacion del indice unico parcial del slot (y no de idempotency_key /
     * public_token). Se mira el mensaje del driver, no el SQL de la consulta.
     */
    private function esViolacionDeUnicoDeSlot(QueryException $e): bool
    {
        $driver = $e->getPrevious()?->getMessage() ?? '';

        return str_contains($driver, 'reservas_web_slot_vivo_unique')      // pgsql: nombre del indice
            || str_contains($driver, 'reservas_web.slot_hora');            // sqlite: columnas del indice
    }

    /** Contexto de log SIN PII (nunca telefono, nombre, nota ni tokens crudos). */
    private function contexto(ReservaWeb $r): array
    {
        return [
            'user_id' => $r->user_id,
            'profesional_id' => $r->profesional_id,
            'reserva_id' => $r->id,
            'device_prefix' => $r->device_hash ? substr($r->device_hash, 0, 8) : null,
        ];
    }
}

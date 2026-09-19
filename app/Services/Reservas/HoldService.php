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
use Illuminate\Support\Facades\DB;
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
        private ReputacionService $reputacion,
    ) {
    }

    /** Hold ya creado por este dispositivo con esta Idempotency-Key (o null). */
    public function buscarReplay(User $user, string $deviceHash, string $idempotencyKey): ?ReservaWeb
    {
        return ReservaWeb::where('user_id', $user->id)
            ->where('device_hash', $deviceHash)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
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
        $replay = $this->buscarReplay($user, $deviceHash, $idempotencyKey);
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

    // ─────────────────────────────────────────────
    // Datos de contacto
    // ─────────────────────────────────────────────

    /**
     * Completa nombre/apellido/whatsapp/nota de un hold vivo. Aca aparece el
     * telefono, asi que recien ahora aplican cooldown y verificacion (ambos
     * liberan el hold). No mueve la expiracion.
     *
     * @throws ReservaPublicaException not_found | hold_expired | already_confirmed | phone_cooldown | verification_required
     */
    public function guardarDatos(User $user, string $token, string $nombre, string $apellido, string $whatsapp, ?string $nota, Carbon $ahora): HoldResultado
    {
        $this->expirarLazy($user, $token, $ahora);
        $reserva = DB::transaction(function () use ($user, $token, $nombre, $apellido, $whatsapp, $nota, $ahora) {
            $reserva = $this->cargarVivo($user, $token, $ahora);
            $phoneHash = $this->reputacion->hashTelefono($whatsapp);

            $espera = $this->reputacion->cooldownRestante($user->id, $phoneHash, $ahora->timestamp);
            if ($espera > 0) {
                $this->cerrar($reserva, 'cooldown');
                Log::info('reserva.abuse.cooldown', $this->contexto($reserva) + ['motivo' => 'phone_cooldown']);

                return ReservaPublicaException::phoneCooldown($espera);
            }

            if (config('reservas.verificacion_habilitada')
                && $this->reputacion->necesitaVerificacion($user->id, $reserva->device_hash, $phoneHash, $ahora->timestamp)) {
                $this->cerrar($reserva, 'verificacion');
                Log::info('reserva.abuse.verification_required', $this->contexto($reserva) + ['motivo' => 'verification_required']);

                return ReservaPublicaException::verificationRequired();
            }

            // El mismo telefono desde otro dispositivo: queda solo el hold mas reciente.
            ReservaWeb::where('user_id', $user->id)->where('id', '!=', $reserva->id)->vivos($ahora->timestamp)->whereNotNull('telefono')->get()
                ->filter(fn (ReservaWeb $otra) => $this->reputacion->hashTelefono((string) $otra->telefono) === $phoneHash)
                ->each(function (ReservaWeb $otra) {
                    $this->cerrar($otra, 'telefono_duplicado');
                    Log::info('reserva.hold.released', $this->contexto($otra) + ['motivo' => 'telefono_duplicado']);
                });

            $reserva->update([
                'nombre' => $nombre,
                'apellido' => $apellido,
                'nombre_completo' => trim("{$nombre} {$apellido}"),
                'telefono' => $whatsapp,
                'nota' => $nota,
            ]);

            return $reserva;
        });

        // El cierre por abuso se persiste (commit) y recien despues se informa el error.
        if ($reserva instanceof ReservaPublicaException) {
            throw $reserva;
        }

        return new HoldResultado($reserva);
    }

    // ─────────────────────────────────────────────
    // Pago
    // ─────────────────────────────────────────────

    /**
     * Pasa a pending_payment y extiende la expiracion UNA sola vez a
     * max(actual, ahora + ventana de pago). Idempotente. Es un STUB de checkout
     * hasta la slice de Mercado Pago.
     *
     * @throws ReservaPublicaException not_found | hold_expired | datos_required
     */
    public function iniciarPago(User $user, string $token, Carbon $ahora): HoldResultado
    {
        $this->expirarLazy($user, $token, $ahora);

        return DB::transaction(function () use ($user, $token, $ahora) {
            $reserva = $this->cargar($user, $token, $ahora);

            if ($reserva->estado === 'confirmed') {
                return new HoldResultado($reserva, true);
            }
            if (! in_array($reserva->estado, ReservaWeb::ESTADOS_BLOQUEANTES, true)) {
                throw ReservaPublicaException::holdExpired();
            }
            if (! $reserva->nombre || ! $reserva->telefono) {
                throw ReservaPublicaException::datosRequired();
            }
            if ($reserva->pago_extendido) {
                return new HoldResultado($reserva, true);
            }

            $reserva->update([
                'estado' => 'pending_payment',
                'pago_extendido' => true,
                'expira_en' => max((int) $reserva->expira_en, $ahora->timestamp + $this->politica->pagoMinutos((bool) $reserva->alta_ocupacion) * 60),
            ]);
            Log::info('reserva.hold.extended', $this->contexto($reserva) + ['motivo' => 'pago_iniciado']);

            return new HoldResultado($reserva);
        });
    }

    // ─────────────────────────────────────────────
    // Liberar / estado
    // ─────────────────────────────────────────────

    /**
     * Libera un hold vivo (cancelled/liberada). Idempotente; NO penaliza. Un
     * hold ya vencido queda expirado (con su reputacion); uno confirmado es 409.
     *
     * @throws ReservaPublicaException not_found | already_confirmed
     */
    public function liberar(User $user, string $token, Carbon $ahora): void
    {
        DB::transaction(function () use ($user, $token, $ahora) {
            $reserva = $this->cargar($user, $token, $ahora);

            if ($reserva->estado === 'confirmed') {
                throw ReservaPublicaException::yaConfirmada();
            }
            if (in_array($reserva->estado, ReservaWeb::ESTADOS_BLOQUEANTES, true)) {
                $this->cerrar($reserva, 'liberada');
                Log::info('reserva.hold.released', $this->contexto($reserva) + ['motivo' => 'liberada']);
            }
        });
    }

    /** Estado actual (con expiracion lazy: un hold vencido se lee como expired). */
    public function estado(User $user, string $token, Carbon $ahora): ReservaWeb
    {
        return DB::transaction(fn () => $this->cargar($user, $token, $ahora));
    }

    // ─────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────

    /** Busca por token (del salon), toma la fila con lock y aplica la expiracion lazy. */
    private function cargar(User $user, string $token, Carbon $ahora): ReservaWeb
    {
        $reserva = ReservaWeb::where('user_id', $user->id)->where('public_token', $token)->lockForUpdate()->first();
        if (! $reserva) {
            throw ReservaPublicaException::notFound();
        }

        if (in_array($reserva->estado, ReservaWeb::ESTADOS_BLOQUEANTES, true)
            && $reserva->expira_en !== null && $reserva->expira_en <= $ahora->timestamp) {
            $this->expirar->expirarUna($reserva, $ahora->timestamp);
            $reserva->refresh();
        }

        return $reserva;
    }

    /** Como cargar(), pero exige que siga vivo (held / pending_payment). */
    private function cargarVivo(User $user, string $token, Carbon $ahora): ReservaWeb
    {
        $reserva = $this->cargar($user, $token, $ahora);

        if ($reserva->estado === 'confirmed') {
            throw ReservaPublicaException::yaConfirmada();
        }
        if (! in_array($reserva->estado, ReservaWeb::ESTADOS_BLOQUEANTES, true)) {
            throw ReservaPublicaException::holdExpired();
        }

        return $reserva;
    }

    /**
     * Expira (y penaliza) el hold si ya vencio, FUERA de la transaccion del
     * caller: si el caller despues lanza (410), el vencimiento no se revierte.
     */
    private function expirarLazy(User $user, string $token, Carbon $ahora): void
    {
        $reserva = ReservaWeb::where('user_id', $user->id)->where('public_token', $token)->first();
        if ($reserva && in_array($reserva->estado, ReservaWeb::ESTADOS_BLOQUEANTES, true)
            && $reserva->expira_en !== null && $reserva->expira_en <= $ahora->timestamp) {
            $this->expirar->expirarUna($reserva, $ahora->timestamp);
        }
    }

    private function cerrar(ReservaWeb $reserva, string $motivo): void
    {
        $reserva->update(['estado' => 'cancelled', 'motivo_cierre' => $motivo]);
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

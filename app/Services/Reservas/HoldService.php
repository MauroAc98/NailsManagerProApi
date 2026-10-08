<?php

namespace App\Services\Reservas;

use App\Exceptions\ReservaPublicaException;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\User;
use App\Services\Servicios\PromoComponentes;
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
 *
 * combo-multi-profesional (PR 3b): retener() recibe `asignaciones` (misma
 * forma que disponibilidad). Un unico grupo sin promo componentizada sigue el
 * camino LEGACY sin cambios (retenerLegacy/intentar, un solo SlotLock::conLock,
 * Rule L). Una promo componentizada o 2+ grupos sueltos exige `modo` (el que
 * el inicio ofrecido tenia) y corre por retenerPlan/intentarPlan, lockeando
 * TODAS las profesionales involucradas con SlotLock::conLocks (orden
 * ascendente, sin deadlocks) y re-validando el plan EXACTO dentro del lock.
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
        private PromoComponentes $promoComponentes,
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
     * @param  array<int, array{servicio_ids: array<int,int>, profesional_id?: int|null}>  $asignaciones
     * @throws ReservaPublicaException validation 422 | slot_taken 409
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException profesional ajena o inactiva (404)
     */
    public function retener(
        User $user,
        array $asignaciones,
        ?string $modo,
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
        $resuelto = $this->resolverAsignaciones($user, $asignaciones);

        if (! $resuelto['usarPlanes']) {
            return $this->retenerLegacy($user, $resuelto['servicioIds'], $resuelto['profesionalId'], $fecha, $hora, $deviceHash, $idempotencyKey, $ahora);
        }

        if ($modo === null) {
            throw ReservaPublicaException::validacion('Falta el modo de la reserva.');
        }

        return $this->retenerPlan($user, $resuelto['promo'], $resuelto['gruposSueltos'], $modo, $fecha, $hora, $deviceHash, $idempotencyKey, $ahora);
    }

    /** Camino LEGACY (un unico grupo, sin promo componentizada): identico a antes de PR 3b (Rule L). */
    private function retenerLegacy(
        User $user,
        array $servicioIds,
        ?int $profesionalId,
        string $fecha,
        string $hora,
        string $deviceHash,
        string $idempotencyKey,
        Carbon $ahora,
    ): HoldResultado {
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
     * Camino PLAN (promo componentizada y/o 2+ grupos sueltos multi-profesional):
     * `modo` debe ser exactamente el de un plan calculado ahora mismo (si ya
     * no lo es -stale offer-, 409 slot_taken: mismo codigo que slot_taken
     * legacy, sin codigo nuevo). Lockea TODAS las profesionales del plan.
     *
     * @param  array<int, GrupoSuelto>  $gruposSueltos
     */
    private function retenerPlan(
        User $user,
        ?PromoInput $promo,
        array $gruposSueltos,
        string $modo,
        string $fecha,
        string $hora,
        string $deviceHash,
        string $idempotencyKey,
        Carbon $ahora,
    ): HoldResultado {
        $anticipacion = (int) config('reservas.anticipacion_minutos', 120);
        if (Carbon::parse("{$fecha} {$hora}")->lt($ahora->copy()->addMinutes($anticipacion))) {
            throw ReservaPublicaException::slotTaken();
        }

        $planes = (new TramosResolver())->planes($promo, $gruposSueltos, $this->promoComponentes->paraleloHabilitado($user));
        $plan = collect($planes)->first(fn (PlanReserva $p) => $p->modo === $modo);
        if ($plan === null) {
            throw ReservaPublicaException::slotTaken();
        }

        $profesionalIds = array_values(array_unique(array_column($plan->tramos, 'profesional_id')));

        try {
            $reserva = $this->lock->conLocks($profesionalIds, fn () => $this->intentarPlan(
                $user, $plan, $fecha, $hora, $deviceHash, $idempotencyKey, $ahora, $promo?->servicioId,
            ));
        } catch (SlotNoDisponible) {
            Log::info('reserva.hold.slot_taken', [
                'user_id' => $user->id,
                'profesional_id' => $plan->tramos[0]['profesional_id'],
                'reserva_id' => null,
                'device_prefix' => substr($deviceHash, 0, 8),
                'motivo' => 'slot_taken',
            ]);
            throw ReservaPublicaException::slotTaken();
        } catch (QueryException $e) {
            $previa = ReservaWeb::where('user_id', $user->id)->where('device_hash', $deviceHash)->where('idempotency_key', $idempotencyKey)->first();
            if ($previa) {
                return new HoldResultado($previa, true);
            }
            throw $e;
        }

        Log::info('reserva.hold.created', $this->contexto($reserva) + ['motivo' => null]);

        return new HoldResultado($reserva);
    }

    /**
     * Resuelve `asignaciones` (misma regla que PublicController::resolverAsignaciones):
     * un unico grupo sin promo componentizada es legacy; una promo componentizada
     * o 2+ grupos (cada uno con profesional explicita) usa planes.
     *
     * @param  array<int, array{servicio_ids: array<int,int>, profesional_id?: int|null}>  $asignaciones
     * @return array{usarPlanes: bool, servicioIds?: array<int,int>, profesionalId?: ?int, promo?: ?PromoInput, gruposSueltos?: array<int, GrupoSuelto>}
     */
    private function resolverAsignaciones(User $user, array $asignaciones): array
    {
        $todosLosIds = array_values(array_unique(array_merge(
            ...array_map(fn (array $a) => $a['servicio_ids'], $asignaciones),
        )));
        $servicios = Servicio::where('user_id', $user->id)->where('activo', true)->whereIn('id', $todosLosIds)->get()->keyBy('id');
        if ($servicios->count() !== count($todosLosIds)) {
            throw ReservaPublicaException::validacion('Uno o más servicios no son válidos.');
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
                    throw ReservaPublicaException::validacion('No se pueden combinar dos promos en la misma reserva.');
                }
                $promo = $this->promoComponentes->promoInput($unico);
                continue;
            }

            $profesionalId = $asignacion['profesional_id'] ?? null;
            if ($profesionalId === null) {
                if ($multiplesGrupos) {
                    throw ReservaPublicaException::validacion('Elegí una profesional para cada servicio.');
                }
                $legacy = [$ids, null];
                continue;
            }

            if (! $multiplesGrupos) {
                $legacy = [$ids, (int) $profesionalId];
                continue;
            }

            $profesional = Profesional::resolverParaUsuario($user, (int) $profesionalId);
            if (count(array_diff($ids, $profesional->servicios()->pluck('servicios.id')->all())) > 0) {
                throw ReservaPublicaException::validacion('La profesional no ofrece todos los servicios elegidos.');
            }
            $gruposSueltos[] = new GrupoSuelto($profesional->id, $ids, (int) $grupoServicios->sum('duracion_minutos'));
        }

        if ($promo === null && count($gruposSueltos) < 2) {
            return ['usarPlanes' => false, 'servicioIds' => $legacy[0], 'profesionalId' => $legacy[1]];
        }

        return ['usarPlanes' => true, 'promo' => $promo, 'gruposSueltos' => $gruposSueltos];
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
        $this->liberarHoldAnterior($user, $deviceHash, $profesional->id);

        // 2) Re-check bajo lock: turnos confirmados + holds vivos de ESTA profesional + lead time.
        $anticipacion = (int) config('reservas.anticipacion_minutos', 120);
        if (! $this->disponibilidad->estaLibre($profesional->id, $fecha, $hora, $duracion, $ahora, null, $anticipacion)) {
            throw new SlotNoDisponible();
        }

        // 3) Ordena filas vencidas en este mismo inicio para que no choquen con el indice unico.
        $this->expirarVencidasEnElInicio($profesional->id, $fecha, $hora, $ahora);

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
            return ReservaWeb::create($this->conPrecioTotal([
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
            ]));
        } catch (QueryException $e) {
            if ($this->esViolacionDeUnicoDeSlot($e)) {
                throw new SlotNoDisponible();
            }
            throw $e;
        }
    }

    /**
     * Corre DENTRO de SlotLock::conLocks (transaccion abierta, TODAS las
     * profesionales del plan ya lockeadas). Re-valida el plan EXACTO: cada
     * tramo sigue alineado a un slot activo de su propia profesional
     * (AlineacionSlots) y sigue libre (estaLibre) en su propio horario. La
     * ancla (para el indice unico y el log) es la profesional del primer tramo.
     */
    private function intentarPlan(
        User $user,
        PlanReserva $plan,
        string $fecha,
        string $hora,
        string $deviceHash,
        string $idempotencyKey,
        Carbon $ahora,
        ?int $promoServicioId = null,
    ): ReservaWeb {
        $ancla = (int) $plan->tramos[0]['profesional_id'];
        $this->liberarHoldAnterior($user, $deviceHash, $ancla);

        $profesionalIds = array_values(array_unique(array_column($plan->tramos, 'profesional_id')));
        $slotsPorProfesional = Profesional::whereIn('id', $profesionalIds)->get()
            ->mapWithKeys(fn (Profesional $p) => [$p->id => $this->disponibilidad->horasActivas($user, $p)])->all();
        if ((new AlineacionSlots())->primerDesalineado($plan->tramos, $hora, $slotsPorProfesional) !== null) {
            throw new SlotNoDisponible();
        }

        $inicioLider = Carbon::parse("{$fecha} {$hora}");
        foreach ($plan->tramos as $tramo) {
            $inicio = $inicioLider->copy()->addMinutes($tramo['offset_minutos']);
            if (! $this->disponibilidad->estaLibre($tramo['profesional_id'], $fecha, $inicio->format('H:i'), $tramo['duracion_minutos'], $ahora)) {
                throw new SlotNoDisponible();
            }
        }

        $this->expirarVencidasEnElInicio($ancla, $fecha, $hora, $ahora);

        try {
            return ReservaWeb::create($this->conPrecioTotal([
                'user_id' => $user->id,
                'profesional_id' => $ancla,
                'public_token' => ReservaWeb::generarToken(),
                'servicio_ids' => array_values(array_unique(array_merge(...array_column($plan->tramos, 'servicio_ids')))),
                'fecha' => $fecha,
                'slot_hora' => $hora . ':00',
                'duracion_total_minutos' => $plan->duracionTotalMinutos(),
                'estado' => 'held',
                // Alta ocupacion no se calcula para multi-tramo (deviation documentada):
                // siempre usa la ventana estandar de hold, nunca la acortada.
                'expira_en' => $ahora->timestamp + $this->politica->holdMinutos(false) * 60,
                'alta_ocupacion' => false,
                'device_hash' => $deviceHash,
                'idempotency_key' => $idempotencyKey,
                'tramos' => $plan->tramos,
                'tramos_modo' => $plan->modo,
                'promo_servicio_id' => $promoServicioId,
            ]));
        } catch (QueryException $e) {
            if ($this->esViolacionDeUnicoDeSlot($e)) {
                throw new SlotNoDisponible();
            }
            throw $e;
        }
    }

    /**
     * Snapshot del precio total (TotalReserva, pesos enteros) al crear el hold:
     * la seña de la reserva se calcula sobre este valor. null = desconocido.
     *
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    private function conPrecioTotal(array $atributos): array
    {
        $total = (int) round((new TotalReserva())->de(new ReservaWeb($atributos)));
        $atributos['precio_total'] = $total > 0 ? $total : null;

        return $atributos;
    }

    /** Libera el hold bloqueante previo de ESTE dispositivo (si lo hay); se revierte si el intento falla. */
    private function liberarHoldAnterior(User $user, string $deviceHash, int $profesionalIdParaLog): void
    {
        $liberadas = ReservaWeb::where('user_id', $user->id)
            ->where('device_hash', $deviceHash)
            ->bloqueantes()
            ->update(['estado' => 'cancelled', 'motivo_cierre' => 'reemplazada', 'updated_at' => now()]);
        if ($liberadas > 0) {
            Log::info('reserva.hold.released', [
                'user_id' => $user->id,
                'profesional_id' => $profesionalIdParaLog,
                'reserva_id' => null,
                'device_prefix' => substr($deviceHash, 0, 8),
                'motivo' => 'reemplazada',
            ]);
        }
    }

    /** Ordena (expira) filas vencidas de ESTE mismo inicio para que no choquen con el indice unico parcial. */
    private function expirarVencidasEnElInicio(int $profesionalId, string $fecha, string $hora, Carbon $ahora): void
    {
        ReservaWeb::where('profesional_id', $profesionalId)
            ->where('fecha', $fecha)
            ->where('slot_hora', $hora . ':00')
            ->bloqueantes()
            ->where('expira_en', '<=', $ahora->timestamp)
            ->get()
            ->each(fn (ReservaWeb $vieja) => $this->expirar->expirarUna($vieja, $ahora->timestamp));
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

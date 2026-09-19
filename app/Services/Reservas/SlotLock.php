<?php

namespace App\Services\Reservas;

use App\Models\Profesional;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Serializa las escrituras que deciden sobre la agenda de UNA profesional
 * (crear hold, confirmar): todo writer nuevo de holds/turnos online debe pasar
 * por aca. Siempre corre en transaccion.
 *
 * - pgsql: pg_advisory_xact_lock(NAMESPACE_LOCK, profesional_id) (2 ints), se
 *   libera solo al terminar la transaccion.
 * - resto (sqlite en tests / mysql): SELECT ... FOR UPDATE sobre la fila de la
 *   profesional (sqlite serializa escritores igualmente).
 *
 * Se toma UN lock por vez (nunca anidados de profesionales distintas): sin deadlocks.
 */
class SlotLock
{
    /** Namespace del advisory lock (evita chocar con otros usos de advisory locks). */
    public const NAMESPACE_LOCK = 7301;

    public function __construct(private ?string $driver = null)
    {
    }

    /**
     * @template T
     * @param  Closure(): T  $fn
     * @return T
     */
    public function conLock(int $profesionalId, Closure $fn): mixed
    {
        return DB::transaction(function () use ($profesionalId, $fn) {
            $this->adquirir($profesionalId);

            return $fn();
        });
    }

    private function adquirir(int $profesionalId): void
    {
        $driver = $this->driver ?? DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->ejecutar('SELECT pg_advisory_xact_lock(?, ?)', [self::NAMESPACE_LOCK, $profesionalId]);

            return;
        }

        Profesional::whereKey($profesionalId)->lockForUpdate()->first();
    }

    /** Seam de test: en la suite (sqlite) la funcion de pgsql no existe. */
    protected function ejecutar(string $sql, array $bindings): void
    {
        DB::select($sql, $bindings);
    }
}

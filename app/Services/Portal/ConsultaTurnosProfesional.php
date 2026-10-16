<?php

namespace App\Services\Portal;

use App\Models\ProfesionalVinculo;
use App\Models\Turno;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read side of the professional's agenda. Every query starts from baseQuery(),
 * which pins the negocio and the professional to the ACTIVE LINK. Nothing in
 * this class reads request input for those two ids, so no parameter a client
 * sends can widen what a professional sees.
 */
class ConsultaTurnosProfesional
{
    /** Rows returned by one list call; one more is fetched to know if there were more. */
    public const LIMITE = 500;

    /**
     * Explicit allow-list (not "!= cancelado") so a state added in the future
     * stays hidden until somebody decides it is visible. Cancelled turnos are
     * never shown to professionals.
     */
    private const ESTADOS_VISIBLES = ['confirmado', 'completado'];

    /**
     * Turnos of the link's professional inside the link's negocio. user_id is
     * redundant with profesional_id on purpose (belt and braces) and lets
     * Postgres use the (user_id, fecha_hora, estado) index.
     *
     * @return Builder<Turno>
     */
    public function baseQuery(ProfesionalVinculo $vinculo): Builder
    {
        return Turno::query()
            ->where('user_id', $vinculo->profesional->user_id)
            ->where('profesional_id', $vinculo->profesional_id)
            ->whereIn('estado', self::ESTADOS_VISIBLES);
    }

    /**
     * Turnos of one day (default: today in the application timezone), ordered
     * by time and id, at most LIMITE of them.
     *
     * @return array{turnos: Collection<int, Turno>, truncated: bool}
     */
    public function listar(ProfesionalVinculo $vinculo, ?string $fecha): array
    {
        $turnos = $this->baseQuery($vinculo)
            ->delaFecha($fecha ?? now(config('app.timezone'))->toDateString())
            ->with($this->relaciones())
            ->orderBy('fecha_hora')
            ->orderBy('id')
            ->limit(self::LIMITE + 1)
            ->get();

        $truncated = $turnos->count() > self::LIMITE;

        return [
            'turnos' => $truncated ? $turnos->take(self::LIMITE)->values() : $turnos,
            'truncated' => $truncated,
        ];
    }

    /** One of HER visible turnos, or null (not hers, other negocio, cancelled, nonexistent). */
    public function buscar(ProfesionalVinculo $vinculo, int $id): ?Turno
    {
        return $this->baseQuery($vinculo)
            ->with($this->relaciones())
            ->whereKey($id)
            ->first();
    }

    /**
     * Only the columns the mapper prints, so prices, phone numbers and the like
     * are not even read (the services pivot still carries its prices because
     * Turno::servicios() declares them; the mapper never prints them).
     *
     * @return array<int|string, mixed>
     */
    private function relaciones(): array
    {
        return [
            'cliente:id,nombre,apellido',
            'servicios' => fn ($q) => $q->select('servicios.id', 'servicios.nombre')->orderBy('servicios.id'),
            'grupo:id,promo_servicio_id',
            'grupo.promo:id,nombre',
        ];
    }
}

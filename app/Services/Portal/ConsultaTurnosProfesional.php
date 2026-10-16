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
     * Turnos from $desde to $hasta, both days inclusive (default: today in the
     * application timezone), ordered by time and id, at most LIMITE of them.
     * $palabras (lowercased) narrow it to clients whose nombre/apellido match,
     * $servicioId to turnos that include that service. The caller has already
     * checked that the service is hers.
     *
     * @param  list<string>  $palabras
     * @return array{turnos: Collection<int, Turno>, truncated: bool}
     */
    public function listar(ProfesionalVinculo $vinculo, ?string $desde = null, ?string $hasta = null, array $palabras = [], ?int $servicioId = null): array
    {
        $hoy = now(config('app.timezone'))->toDateString();

        $consulta = $this->baseQuery($vinculo)->delRango($desde ?? $hoy, $hasta ?? $desde ?? $hoy);

        if ($palabras !== []) {
            $this->filtrarPorCliente($consulta, $palabras);
        }

        if ($servicioId !== null) {
            $consulta->whereHas('servicios', fn (Builder $servicio) => $servicio->where('servicios.id', $servicioId));
        }

        $turnos = $consulta
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

    /**
     * Days of the month "YYYY-MM" on which she has at least one visible turno,
     * with how many, in ascending date order. One query, reading only
     * fecha_hora: the stored wall-clock strings are used as they are (no
     * Eloquent cast, no timezone conversion), so a turno at 23:59:59 stays on
     * its local day. Counting is done here because one professional's month is
     * small.
     *
     * @return list<array{fecha: string, cantidad: int}>
     */
    public function marcas(ProfesionalVinculo $vinculo, string $mes): array
    {
        $porDia = [];

        // ORDER BY makes the days come out ascending: counting keeps first-seen order.
        $fechas = $this->baseQuery($vinculo)->delMes($mes)->orderBy('fecha_hora')->toBase()->pluck('fecha_hora');

        foreach ($fechas as $fechaHora) {
            $dia = substr((string) $fechaHora, 0, 10);
            $porDia[$dia] = ($porDia[$dia] ?? 0) + 1;
        }

        $marcas = [];
        foreach ($porDia as $fecha => $cantidad) {
            $marcas[] = ['fecha' => (string) $fecha, 'cantidad' => $cantidad];
        }

        return $marcas;
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
     * Every word must be contained in the client's nombre or apellido, ignoring
     * case. Deliberately nothing else is searched (phone, notes, services): a
     * search over fields the professional cannot see would let her read them
     * one probe at a time. LIKE metacharacters in the word are escaped with '!'
     * so '%' and '_' are literal; the explicit ESCAPE works on sqlite and on
     * Postgres alike (no ILIKE). LOWER() folds only ASCII on sqlite; Postgres
     * folds per locale.
     *
     * @param  Builder<Turno>  $consulta
     * @param  list<string>  $palabras
     */
    private function filtrarPorCliente(Builder $consulta, array $palabras): void
    {
        $consulta->whereHas('cliente', function (Builder $cliente) use ($palabras) {
            foreach ($palabras as $palabra) {
                $patron = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $palabra).'%';

                $cliente->where(fn (Builder $coincide) => $coincide
                    ->whereRaw("LOWER(COALESCE(clientes.nombre, '')) LIKE ? ESCAPE '!'", [$patron])
                    ->orWhereRaw("LOWER(COALESCE(clientes.apellido, '')) LIKE ? ESCAPE '!'", [$patron]));
            }
        });
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

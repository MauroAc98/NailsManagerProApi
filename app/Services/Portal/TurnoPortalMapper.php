<?php

namespace App\Services\Portal;

use App\Models\Turno;
use Illuminate\Support\Carbon;

/**
 * The only place a Turno becomes what a professional sees in the portal.
 *
 * Every key is written out by hand: never toArray()/toJson(), never a
 * "hidden" list. A column added to the turnos, clientes or servicios tables
 * tomorrow therefore stays invisible until somebody puts it here on purpose.
 * The exact key sets are pinned by TurnoPortalMapperTest and the canary-value
 * leak test (ProTurnoFugasTest).
 *
 * Expects the relations cliente, servicios and grupo.promo to be loaded (the
 * query class eager-loads them with column lists); it never triggers a lazy
 * load on purpose, so the number of queries does not grow with the turnos.
 */
class TurnoPortalMapper
{
    public const NOTAS_MAX_CARACTERES = 500;

    /**
     * @return array{
     *     id: int,
     *     fecha_hora: string,
     *     duracion_total_minutos: int,
     *     estado_visual: string,
     *     notas: ?string,
     *     cliente: array{nombre: ?string, apellido: ?string},
     *     servicios: list<array{id: int, nombre: string}>,
     *     promo: ?array{nombre: string}
     * }
     */
    public function mapear(Turno $turno): array
    {
        $cliente = $turno->cliente;
        $promo = $turno->grupo?->promo;

        return [
            'id' => $turno->id,
            // Stored wall clock, no zone suffix (same format as the owner's agenda).
            'fecha_hora' => $turno->fecha_hora->format('Y-m-d\TH:i:s'),
            'duracion_total_minutos' => (int) $turno->duracion_total_minutos,
            'estado_visual' => $this->estadoVisual($turno),
            'notas' => $this->notas($turno->notas),
            // Always an object: a turno without client (deleted or walk-in)
            // keeps the same shape, with null fields.
            'cliente' => [
                'nombre' => $cliente?->nombre,
                'apellido' => $cliente?->apellido,
            ],
            // Only the services attached to HER turno row: in a promo that is
            // her step, never the other professionals' steps.
            'servicios' => $turno->servicios
                ->map(fn ($servicio) => ['id' => $servicio->id, 'nombre' => $servicio->nombre])
                ->values()
                ->all(),
            'promo' => $promo === null ? null : ['nombre' => $promo->nombre],
        ];
    }

    /**
     * Same rule as the owner's agenda (DecoraListadoDeTurnos::calcularEstadoVisual,
     * a private trait method, so it is repeated here and pinned by a parity
     * test): "en_curso" is computed on the fly for a confirmed turno whose
     * interval contains now; "completado" is persisted, never computed.
     */
    public function estadoVisual(Turno $turno): string
    {
        if ($turno->estado !== 'confirmado') {
            return $turno->estado;
        }

        $ahora = Carbon::now();
        $inicio = Carbon::parse($turno->fecha_hora);
        $fin = $inicio->copy()->addMinutes($turno->duracion_total_minutos);

        return $inicio->lt($ahora) && $fin->gt($ahora) ? 'en_curso' : 'confirmado';
    }

    /**
     * Free text typed by the client on the public booking page: untrusted. It
     * is capped (by characters, not bytes) and returned verbatim as a JSON
     * string; the frontend must render it as text.
     */
    private function notas(?string $notas): ?string
    {
        if ($notas === null || trim($notas) === '') {
            return null;
        }

        return mb_substr($notas, 0, self::NOTAS_MAX_CARACTERES);
    }
}

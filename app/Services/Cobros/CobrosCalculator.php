<?php

namespace App\Services\Cobros;

use App\Models\Turno;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reglas de la pantalla Cobros. Son las mismas que aplica el frontend a un
 * turno suelto (lib/cobros.ts, derivarFila): si cambia una, cambia la otra.
 *
 * Trabaja sobre turnos con `servicios`, `cliente` y `reservaWeb.pagoSena` ya
 * cargados. Cada fila es ['turno' => Turno, 'cobro' => array].
 *
 * Estado de pago ('pago'):
 *  - Finalizado ('completado'):
 *      * algún servicio sin precio registrado → sinprecio
 *      * todos registrados y total > 0        → todo
 *      * todos registrados y total = 0        → nada
 *  - Confirmado (no hay precio registrado, se usa el de lista):
 *      * sin seña aprobada                    → nada
 *      * seña aprobada >= precio de lista > 0 → todo
 *      * seña aprobada menor                  → sena
 *  Solo cuenta la seña 'aprobado'; pendiente/rechazado/expirado = sin seña.
 */
final class CobrosCalculator
{
    public const PAGOS = ['sena', 'todo', 'nada', 'sinprecio'];

    /** Dinero sin ceros de sobra: 4000.0 sale como 4000. */
    public static function monto(float $valor): int|float
    {
        $valor = round($valor, 2);

        return $valor == floor($valor) ? (int) $valor : $valor;
    }

    private static function precioDeLista(mixed $precio): ?float
    {
        return $precio === null || $precio === '' ? null : (float) $precio;
    }

    /** @return array<string, mixed> */
    public static function derivar(Turno $turno): array
    {
        $finalizado = $turno->estado === 'completado';
        $pagoSena = $turno->reservaWeb?->pagoSena;
        $reservaId = $pagoSena?->reserva_web_id;
        $sena = $pagoSena !== null && $pagoSena->estado === 'aprobado' ? (float) $pagoSena->monto : 0.0;

        $listas = $turno->servicios->map(fn ($s) => self::precioDeLista($s->precio));
        $listaCompleta = $turno->servicios->isNotEmpty() && $listas->every(fn ($p) => $p !== null);
        $precioLista = (float) $listas->filter(fn ($p) => $p !== null)->sum();

        $base = [
            'finalizado' => $finalizado,
            'sena' => $sena,
            'reserva_id' => $reservaId,
            'sena_compartida' => false,
            'precio_lista' => $precioLista,
            'lista_completa' => $listaCompleta,
        ];

        if ($finalizado) {
            $conPrecios = $turno->servicios->every(fn ($s) => $s->pivot->precio !== null);
            if ($turno->servicios->isNotEmpty() && ! $conPrecios) {
                return $base + ['pago' => 'sinprecio', 'precio' => null, 'cobrado' => null, 'falta_fila' => null];
            }
            $cobrado = (float) $turno->servicios->sum(fn ($s) => (float) $s->pivot->precio);

            return $base + ['pago' => $cobrado > 0 ? 'todo' : 'nada', 'precio' => $cobrado, 'cobrado' => $cobrado, 'falta_fila' => null];
        }

        $precio = $listaCompleta ? $precioLista : null;
        $pago = 'nada';
        if ($sena > 0) {
            $pago = $precio !== null && $precio > 0 && $sena >= $precio ? 'todo' : 'sena';
        }

        return $base + [
            'pago' => $pago,
            'precio' => $precio,
            'cobrado' => null,
            'falta_fila' => $precio !== null ? max(0.0, $precio - $sena) : null,
        ];
    }

    /**
     * Una fila por turno (los cancelados no se listan). La seña es de la
     * reserva: si más de un turno de la lista la comparte, ninguno muestra
     * "falta" por su cuenta.
     *
     * @param  Collection<int, Turno>  $turnos
     * @return Collection<int, array{turno: Turno, cobro: array<string, mixed>}>
     */
    public static function filas(Collection $turnos): Collection
    {
        $vivos = $turnos->where('estado', '!=', 'cancelado');
        $porReserva = $vivos
            ->map(fn (Turno $t) => $t->reservaWeb?->pagoSena?->reserva_web_id)
            ->filter(fn ($id) => $id !== null)
            ->countBy();

        return $vivos->map(function (Turno $turno) use ($porReserva) {
            $cobro = self::derivar($turno);
            if ($cobro['reserva_id'] !== null && ($porReserva[$cobro['reserva_id']] ?? 0) > 1) {
                $cobro['sena_compartida'] = true;
                $cobro['falta_fila'] = null;
            }

            return ['turno' => $turno, 'cobro' => $cobro];
        })->values();
    }

    /** Más reciente primero; el id desempata para que la paginación sea estable. */
    public static function ordenar(Collection $filas): Collection
    {
        return $filas->sort(function (array $a, array $b) {
            return [$b['turno']->fecha_hora, $b['turno']->id] <=> [$a['turno']->fecha_hora, $a['turno']->id];
        })->values();
    }

    /**
     * Filtra por turno, período y nombre del cliente (NO por pago: los conteos
     * de cada estado se calculan sobre este resultado).
     *
     * @param  Collection<int, array{turno: Turno, cobro: array<string, mixed>}>  $filas
     */
    public static function filtrar(Collection $filas, string $turno, string $periodo, string $buscar, string $hoy): Collection
    {
        [$desde, $hasta] = self::limitesDelPeriodo($periodo, $hoy);
        $buscado = self::normalizar(trim($buscar));

        return $filas->filter(function (array $fila) use ($turno, $desde, $hasta, $buscado) {
            $finalizado = $fila['cobro']['finalizado'];
            if ($turno === 'confirmado' && $finalizado) {
                return false;
            }
            if ($turno === 'finalizado' && ! $finalizado) {
                return false;
            }
            $fecha = $fila['turno']->fecha_hora->format('Y-m-d');
            if (($desde !== null && $fecha < $desde) || ($hasta !== null && $fecha > $hasta)) {
                return false;
            }
            if ($buscado !== '') {
                $cliente = $fila['turno']->cliente;
                if (! str_contains(self::normalizar(trim(($cliente?->nombre ?? '').' '.($cliente?->apellido ?? ''))), $buscado)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    public static function soloPago(Collection $filas, string $pago): Collection
    {
        return $pago === 'todos' ? $filas : $filas->filter(fn (array $f) => $f['cobro']['pago'] === $pago)->values();
    }

    /** @return array{todos: int, sena: int, todo: int, nada: int, sinprecio: int} */
    public static function contar(Collection $filas): array
    {
        $conteo = ['todos' => $filas->count(), 'sena' => 0, 'todo' => 0, 'nada' => 0, 'sinprecio' => 0];
        foreach ($filas as $fila) {
            $conteo[$fila['cobro']['pago']]++;
        }

        return $conteo;
    }

    /**
     * Totales del conjunto filtrado. La seña se suma una vez por reserva (los
     * turnos de un grupo repiten la misma). "Falta cobrar" = precio de lista de
     * los confirmados menos su seña, por reserva y sin bajar de 0; los
     * confirmados sin precio de lista no suman (se desconoce).
     *
     * "Ya cobraste" (cobrado_total) = lo registrado en finalizados, que ya
     * incluye su seña, más las señas de las reservas SIN ningún turno
     * finalizado. No es seña_cobrada + cobrado_finalizados: eso cuenta dos
     * veces la seña de los finalizados.
     *
     * @return array<string, int|float>
     */
    public static function resumir(Collection $filas): array
    {
        $senas = [];
        $faltas = [];
        $conFinalizado = [];
        $cobradoFinalizados = 0.0;
        $sinPrecioCount = 0;
        $sinPrecioEstimado = 0.0;

        foreach ($filas as $fila) {
            $cobro = $fila['cobro'];
            $clave = $cobro['reserva_id'] !== null ? 'r'.$cobro['reserva_id'] : 't'.$fila['turno']->id;
            if ($cobro['sena'] > 0) {
                $senas[$clave] = $cobro['sena'];
            }
            if ($cobro['finalizado']) {
                $conFinalizado[$clave] = true;
                if ($cobro['pago'] === 'sinprecio') {
                    $sinPrecioCount++;
                    $sinPrecioEstimado += $cobro['precio_lista'];
                } else {
                    $cobradoFinalizados += $cobro['cobrado'] ?? 0.0;
                }
            } elseif ($cobro['precio'] !== null) {
                $faltas[$clave] ??= ['precio' => 0.0, 'sena' => $cobro['sena']];
                $faltas[$clave]['precio'] += $cobro['precio'];
            }
        }

        $senaCobrada = array_sum($senas);
        $senaEnPendientes = array_sum(array_diff_key($senas, $conFinalizado));
        $faltaCobrar = array_sum(array_map(fn (array $f) => max(0.0, $f['precio'] - $f['sena']), $faltas));

        return [
            'sena_cobrada' => self::monto($senaCobrada),
            'cobrado_finalizados' => self::monto($cobradoFinalizados),
            'falta_cobrar' => self::monto($faltaCobrar),
            'sin_precio_count' => $sinPrecioCount,
            'sin_precio_estimado' => self::monto($sinPrecioEstimado),
            'sena_en_pendientes' => self::monto($senaEnPendientes),
            'cobrado_total' => self::monto($cobradoFinalizados + $senaEnPendientes),
        ];
    }

    /**
     * Lo necesario para "usar precio de lista en todos": los finalizados sin
     * precio cuyos servicios tienen todos precio de lista.
     *
     * @return array{count: int, total: int|float, items: list<array<string, mixed>>}
     */
    public static function listaBulk(Collection $filas): array
    {
        $items = [];
        $total = 0.0;
        foreach ($filas as $fila) {
            if ($fila['cobro']['pago'] !== 'sinprecio' || ! $fila['cobro']['lista_completa']) {
                continue;
            }
            $total += $fila['cobro']['precio_lista'];
            $items[] = [
                'turno_id' => $fila['turno']->id,
                'precios' => $fila['turno']->servicios->map(fn ($s) => [
                    'servicio_id' => $s->id,
                    'precio' => self::monto((float) $s->precio),
                ])->values()->all(),
            ];
        }

        return ['count' => count($items), 'total' => self::monto($total), 'items' => $items];
    }

    /** @return array{0: ?string, 1: ?string} Límites inclusivos 'Y-m-d'; null = sin tope. */
    private static function limitesDelPeriodo(string $periodo, string $hoy): array
    {
        $dia = Carbon::createFromFormat('Y-m-d', $hoy)->startOfDay();

        return match ($periodo) {
            'hoy' => [$dia->toDateString(), $dia->toDateString()],
            '7dias' => [$dia->copy()->subDays(6)->toDateString(), $dia->toDateString()],
            'mes' => [$dia->copy()->startOfMonth()->toDateString(), $dia->copy()->endOfMonth()->toDateString()],
            'proximos' => [$dia->toDateString(), null],
            default => [null, null],
        };
    }

    private static function normalizar(string $texto): string
    {
        return Str::lower(Str::ascii($texto));
    }
}

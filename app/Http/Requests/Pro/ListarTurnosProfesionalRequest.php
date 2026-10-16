<?php

namespace App\Http\Requests\Pro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Filters of GET /api/pro/turnos. Nothing here names a professional or a
 * negocio: those come only from the validated link (`pro.vinculo`).
 */
class ListarTurnosProfesionalRequest extends FormRequest
{
    /** Longest accepted desde..hasta span, counting both ends. */
    public const MAX_DIAS_RANGO = 366;

    /** Limits of the client search: it is a typing aid, not a query language. */
    public const MAX_CARACTERES_BUSQUEDA = 60;

    public const MAX_PALABRAS_BUSQUEDA = 5;

    /** One message for every way a service can be wrong, so the answer reveals nothing about other negocios. */
    private const MENSAJE_SERVICIO = 'Elegí uno de tus servicios.';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // The lower bound keeps year 0000 (accepted by sqlite, rejected by Postgres) out of the queries.
            'fecha' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'desde' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'buscar' => ['nullable', 'string', 'max:'.self::MAX_CARACTERES_BUSQUEDA],
            'servicio_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** Runs of whitespace count as one space, so "a   b" is two words and 3 characters. */
    protected function prepareForValidation(): void
    {
        $buscar = $this->input('buscar');

        if (is_string($buscar) && mb_check_encoding($buscar, 'UTF-8')) {
            $this->merge(['buscar' => trim(preg_replace('/\s+/u', ' ', $buscar))]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fecha.date_format' => 'La fecha tiene que tener el formato AAAA-MM-DD.',
            'desde.date_format' => 'La fecha "desde" tiene que tener el formato AAAA-MM-DD.',
            'hasta.date_format' => 'La fecha "hasta" tiene que tener el formato AAAA-MM-DD.',
            'fecha.after_or_equal' => 'La fecha tiene que ser posterior al año 2000.',
            'desde.after_or_equal' => 'La fecha "desde" tiene que ser posterior al año 2000.',
            'hasta.after_or_equal' => 'La fecha "hasta" tiene que ser posterior al año 2000.',
            'buscar.string' => 'La búsqueda tiene que ser un texto.',
            'servicio_id.integer' => self::MENSAJE_SERVICIO,
            'servicio_id.min' => self::MENSAJE_SERVICIO,
            'buscar.max' => 'La búsqueda no puede superar '.self::MAX_CARACTERES_BUSQUEDA.' caracteres.',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validarFechas($validator),
            fn (Validator $validator) => $this->validarBusqueda($validator),
            fn (Validator $validator) => $this->validarServicio($validator),
        ];
    }

    /**
     * The days to list, both inclusive: `fecha` is a one-day range, no date
     * filter at all gives [null, null] (the caller then uses today).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function rango(): array
    {
        if ($this->filled('fecha')) {
            return [$this->input('fecha'), $this->input('fecha')];
        }

        return [$this->input('desde'), $this->input('hasta')];
    }

    /**
     * Lowercased words of `buscar` (empty when there is no search). Each one
     * must match the client's nombre or apellido; see ConsultaTurnosProfesional.
     *
     * @return list<string>
     */
    public function palabras(): array
    {
        $buscar = $this->input('buscar');

        if (! is_string($buscar) || $buscar === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', mb_strtolower($buscar))));
    }

    /** The service to filter by, when one was asked for (already checked to be hers). */
    public function servicioId(): ?int
    {
        return $this->filled('servicio_id') ? (int) $this->input('servicio_id') : null;
    }

    /**
     * The service must be one of the services assigned to HER professional in
     * HER negocio. Missing, assigned to someone else and belonging to another
     * negocio all fail the same way, with the same message.
     */
    private function validarServicio(Validator $validator): void
    {
        if ($validator->errors()->has('servicio_id') || ! $this->filled('servicio_id')) {
            return;
        }

        $profesional = $this->attributes->get('pro.vinculo')->profesional;

        $esSuyo = $profesional->servicios()
            ->where('servicios.id', (int) $this->input('servicio_id'))
            ->where('servicios.user_id', $profesional->user_id)
            ->exists();

        if (! $esSuyo) {
            $validator->errors()->add('servicio_id', self::MENSAJE_SERVICIO);
        }
    }

    private function validarBusqueda(Validator $validator): void
    {
        $buscar = $this->input('buscar');

        if ($validator->errors()->has('buscar') || ! is_string($buscar)) {
            return;
        }

        if (! mb_check_encoding($buscar, 'UTF-8')) {
            $validator->errors()->add('buscar', 'La búsqueda tiene caracteres que no podemos leer.');
        } elseif (count($this->palabras()) > self::MAX_PALABRAS_BUSQUEDA) {
            $validator->errors()->add('buscar', 'Buscá con hasta '.self::MAX_PALABRAS_BUSQUEDA.' palabras.');
        }
    }

    private function validarFechas(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['fecha', 'desde', 'hasta'])) {
            return;
        }

        if ($this->filled('fecha') && ($this->filled('desde') || $this->filled('hasta'))) {
            $validator->errors()->add('fecha', 'Usá una sola fecha o un rango desde/hasta, no los dos.');

            return;
        }

        if ($this->filled('desde') xor $this->filled('hasta')) {
            $falta = $this->filled('desde') ? 'hasta' : 'desde';
            $validator->errors()->add($falta, 'Para filtrar por rango hacen falta "desde" y "hasta".');

            return;
        }

        if (! $this->filled('desde')) {
            return;
        }

        $desde = Carbon::createFromFormat('!Y-m-d', $this->input('desde'));
        $hasta = Carbon::createFromFormat('!Y-m-d', $this->input('hasta'));

        if ($hasta->lt($desde)) {
            $validator->errors()->add('hasta', 'La fecha "hasta" no puede ser anterior a "desde".');
        } elseif ($desde->copy()->addDays(self::MAX_DIAS_RANGO - 1)->lt($hasta)) {
            $validator->errors()->add('hasta', 'El rango no puede superar '.self::MAX_DIAS_RANGO.' días.');
        }
    }
}

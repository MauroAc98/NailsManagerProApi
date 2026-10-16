<?php

namespace App\Http\Requests\Pro;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query of GET /api/pro/turnos/marcas: the month to mark. Nothing here names a
 * professional or a negocio: those come only from the validated link
 * (`pro.vinculo`).
 */
class MarcasTurnosProfesionalRequest extends FormRequest
{
    /**
     * First month accepted. It keeps year 0000 (accepted by sqlite, rejected by
     * Postgres) out of the queries. Fixed-width "YYYY-MM" strings compare
     * correctly as text.
     */
    public const MES_MINIMO = '2000-01';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // `\z`, not `$`: `$` would also accept a trailing newline.
            'mes' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])\z/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mes.required' => 'Indicá el mes con el formato AAAA-MM.',
            'mes.string' => 'El mes tiene que tener el formato AAAA-MM.',
            'mes.regex' => 'El mes tiene que tener el formato AAAA-MM.',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function ($validator) {
                $mes = $this->input('mes');

                if (! $validator->errors()->has('mes') && $mes < self::MES_MINIMO) {
                    $validator->errors()->add('mes', 'El mes tiene que ser posterior al año 2000.');
                }
            },
        ];
    }

    /** The validated month, "YYYY-MM". */
    public function mes(): string
    {
        return $this->validated('mes');
    }
}

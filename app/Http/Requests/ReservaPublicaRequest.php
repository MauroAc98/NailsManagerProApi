<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Base de los FormRequest de la reserva online: 422 con {message, code: validation, errors}. */
abstract class ReservaPublicaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Los datos enviados no son válidos.',
            'code' => 'validation',
            'errors' => $validator->errors(),
        ], 422));
    }
}

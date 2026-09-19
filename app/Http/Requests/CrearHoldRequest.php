<?php

namespace App\Http\Requests;

class CrearHoldRequest extends ReservaPublicaRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'servicio_ids'    => 'required|array|min:1|max:10',
            'servicio_ids.*'  => 'integer',
            'profesional_id'  => 'nullable|integer',
            'fecha'           => 'required|date_format:Y-m-d|after_or_equal:today',
            'hora'            => 'required|date_format:H:i',
            'challenge_token' => 'nullable|string|max:2048',
            'idempotency_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_:.\-]+$/'],
        ];
    }
}

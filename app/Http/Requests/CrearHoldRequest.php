<?php

namespace App\Http\Requests;

class CrearHoldRequest extends ReservaPublicaRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /**
     * combo-multi-profesional (PR 3b): mismo shape `asignaciones` que
     * disponibilidad (un grupo por servicio(s) + profesional elegida, o null
     * = "Cualquiera", solo valido con un unico grupo). `modo` es el que el
     * inicio elegido ofrecio (paralelo|secuencia): obligatorio solo cuando la
     * reserva resuelve a un plan multi-tramo (promo componentizada o 2+
     * grupos), lo que HoldService decide server-side.
     */
    public function rules(): array
    {
        return [
            'asignaciones'                   => 'required|array|min:1',
            'asignaciones.*.servicio_ids'    => 'required|array|min:1|max:10',
            'asignaciones.*.servicio_ids.*'  => 'integer',
            'asignaciones.*.profesional_id'  => 'nullable|integer',
            'modo'            => 'nullable|string|in:paralelo,secuencia',
            'fecha'           => 'required|date_format:Y-m-d|after_or_equal:today',
            'hora'            => 'required|date_format:H:i',
            'challenge_token' => 'nullable|string|max:2048',
            'idempotency_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_:.\-]+$/'],
        ];
    }
}

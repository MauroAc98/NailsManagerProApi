<?php

namespace App\Http\Requests;

class GuardarDatosReservaRequest extends ReservaPublicaRequest
{
    public function rules(): array
    {
        return [
            'nombre'   => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'whatsapp' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'nota'     => 'nullable|string|max:300',
        ];
    }
}

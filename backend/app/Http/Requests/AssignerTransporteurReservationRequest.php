<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignerTransporteurReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transporteur_id' => ['required', 'integer', 'exists:transporteurs,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'transporteur_id.required' => 'Le transporteur à assigner est obligatoire.',
            'transporteur_id.exists'   => 'Le transporteur sélectionné est introuvable.',
        ];
    }
}

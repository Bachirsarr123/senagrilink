<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLivraisonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'commande_id'    => ['required', 'integer', 'exists:commandes,id'],
            'transporteur_id'=> ['nullable', 'integer', 'exists:transporteurs,id'],
            'origine'        => ['required', 'string', 'max:255'],
            'destination'    => ['required', 'string', 'max:255'],
            'date_depart'    => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'commande_id.required'   => 'La commande associée est obligatoire.',
            'commande_id.exists'     => 'La commande référencée est introuvable.',
            'transporteur_id.exists' => 'Le transporteur sélectionné est introuvable.',
            'origine.required'       => 'L\'adresse d\'origine est obligatoire.',
            'destination.required'   => 'L\'adresse de destination est obligatoire.',
        ];
    }
}

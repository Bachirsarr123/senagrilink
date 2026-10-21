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
            'transporteur_id'=> ['required', 'integer', 'exists:transporteurs,id'],
            'origine'        => ['nullable', 'string', 'max:255'],
            'destination'    => ['nullable', 'string', 'max:255'],
            'destination_latitude'  => ['nullable', 'numeric', 'between:-90,90'],
            'destination_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'date_depart'    => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'commande_id.required'   => 'La commande associée est obligatoire.',
            'commande_id.exists'     => 'La commande référencée est introuvable.',
            'transporteur_id.exists' => 'Le transporteur sélectionné est introuvable.',
            'transporteur_id.required' => 'Choisissez le transporteur de la livraison.',
        ];
    }
}

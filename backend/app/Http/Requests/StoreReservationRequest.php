<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entrepot_id'       => ['required', 'integer', 'exists:entrepots,id'],
            'production_id'     => ['nullable', 'integer', 'exists:productions,id'],
            'produit'           => ['required', 'string', 'max:150'],
            'quantite_reservee' => ['required', 'numeric', 'min:0.01'],
            'date_debut'        => ['required', 'date'],
            'date_fin'          => ['nullable', 'date', 'after_or_equal:date_debut'],
        ];
    }

    public function messages(): array
    {
        return [
            'entrepot_id.required'       => 'L\'entrepôt à réserver est obligatoire.',
            'entrepot_id.exists'         => 'L\'entrepôt sélectionné est introuvable.',
            'production_id.exists'       => 'La production référencée est introuvable.',
            'produit.required'           => 'Le type de produit est obligatoire.',
            'quantite_reservee.required' => 'La quantité à réserver est obligatoire.',
            'quantite_reservee.min'      => 'La quantité doit être supérieure à zéro.',
            'date_debut.required'        => 'La date de début de réservation est obligatoire.',
            'date_fin.after_or_equal'    => 'La date de fin doit être postérieure ou égale à la date de début.',
        ];
    }
}

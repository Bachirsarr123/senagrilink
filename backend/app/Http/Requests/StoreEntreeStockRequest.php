<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEntreeStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'production_id' => ['nullable', 'integer', 'exists:productions,id'],
            'produit'       => ['required', 'string', 'max:150'],
            'quantite'      => ['required', 'numeric', 'min:0.01'],
            'date_entree'   => ['nullable', 'date'],
            'seuil_alerte'  => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'produit.required'    => 'Le nom du produit est obligatoire.',
            'quantite.required'   => 'La quantité est obligatoire.',
            'quantite.min'        => 'La quantité doit être supérieure à zéro.',
            'production_id.exists'=> 'La production référencée est introuvable.',
        ];
    }
}

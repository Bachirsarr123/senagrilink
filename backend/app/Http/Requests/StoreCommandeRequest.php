<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommandeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stock_id' => ['required', 'integer', 'exists:stocks,id'],
            'quantite' => ['required', 'numeric', 'min:0.01'],
            // Le prix n'est jamais saisi par l'acheteur : il vient du lot (producteur).
            'prix'     => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'stock_id.required' => 'Le produit à commander est obligatoire.',
            'stock_id.exists'   => 'Le produit sélectionné est introuvable en stock.',
            'quantite.required' => 'La quantité est obligatoire.',
            'quantite.min'      => 'La quantité doit être supérieure à zéro.',
            'prix.prohibited'   => 'Le prix est fixé par le producteur du lot : il ne peut pas être saisi.',
        ];
    }
}

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
            // Une ligne (stock_id + quantite) ou un panier de plusieurs lignes.
            'stock_id' => ['required_without:lignes', 'integer', 'exists:stocks,id'],
            'quantite' => ['required_without:lignes', 'numeric', 'min:0.01'],
            'lignes'   => ['sometimes', 'array', 'min:1', 'max:20'],
            'lignes.*.stock_id' => ['required', 'integer', 'distinct', 'exists:stocks,id'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0.01'],
            // Le prix n'est jamais saisi par l'acheteur : il vient du lot (producteur).
            'prix'     => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'stock_id.required_without' => 'Le produit à commander est obligatoire.',
            'quantite.required_without' => 'La quantité est obligatoire.',
            'lignes.*.stock_id.distinct' => 'Un même produit apparaît deux fois dans le panier.',
            'lignes.*.quantite.min'      => 'La quantité doit être supérieure à zéro.',
            'stock_id.exists'   => 'Le produit sélectionné est introuvable en stock.',
            'quantite.required' => 'La quantité est obligatoire.',
            'quantite.min'      => 'La quantité doit être supérieure à zéro.',
            'prix.prohibited'   => 'Le prix est fixé par le producteur du lot : il ne peut pas être saisi.',
        ];
    }
}

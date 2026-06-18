<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSortieStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stock_id'       => ['required', 'integer', 'exists:stocks,id'],
            'quantite_sortie' => ['required', 'numeric', 'min:0.01'],
            'date_sortie'    => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'stock_id.required'        => 'L\'identifiant du stock est obligatoire.',
            'stock_id.exists'          => 'Le stock référencé est introuvable.',
            'quantite_sortie.required' => 'La quantité à sortir est obligatoire.',
            'quantite_sortie.min'      => 'La quantité doit être supérieure à zéro.',
        ];
    }
}

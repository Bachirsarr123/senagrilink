<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type_culture'      => ['required', 'string', 'max:100'],
            'superficie'        => ['nullable', 'numeric', 'min:0'],
            'date_recolte'      => ['required', 'date'],
            'quantite_estimee'  => ['required', 'numeric', 'min:0'],
            'quantite_reelle'   => ['nullable', 'numeric', 'min:0'],
            'statut'            => ['nullable', 'string', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'type_culture.required'     => 'Le type de culture est obligatoire.',
            'date_recolte.required'     => 'La date de récolte est obligatoire.',
            'date_recolte.date'         => 'La date de récolte n\'est pas valide.',
            'quantite_estimee.required' => 'La quantité estimée est obligatoire.',
        ];
    }
}

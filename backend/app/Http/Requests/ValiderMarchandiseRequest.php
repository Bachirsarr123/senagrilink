<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValiderMarchandiseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantite_reelle' => ['required', 'numeric', 'min:0.01'],
            'observation'     => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantite_reelle.required' => 'La quantité vérifiée est obligatoire.',
            'quantite_reelle.min'      => 'La quantité doit être supérieure à zéro.',
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCommandeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantite' => ['sometimes', 'numeric', 'min:0.01'],
            'prix'     => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantite.min'    => 'La quantité doit être supérieure à zéro.',
            'prix.prohibited' => 'Le prix est fixé par le producteur du lot : il ne peut pas être modifié.',
        ];
    }
}

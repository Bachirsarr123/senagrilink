<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type_culture'     => ['sometimes', 'string', 'max:100'],
            'superficie'       => ['nullable', 'numeric', 'min:0'],
            'date_recolte'     => ['sometimes', 'date'],
            'quantite_estimee' => ['sometimes', 'numeric', 'min:0'],
            'quantite_reelle'  => ['nullable', 'numeric', 'min:0'],
            'statut'           => ['sometimes', 'string', 'max:30'],
        ];
    }
}

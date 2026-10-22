<?php

namespace App\Http\Requests;

use App\Models\Production;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'prix_unitaire'    => ['sometimes', 'numeric', 'min:1', 'max:10000000'],
            // R2 — le producteur ne fixe pas le statut (réception par l'entrepôt) ;
            // l'administrateur peut corriger, uniquement vers un statut connu.
            'statut'           => $this->user()?->role === 'administrateur'
                ? ['sometimes', Rule::in(Production::STATUTS)]
                : ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'statut.prohibited' => 'Le statut est fixé par la plateforme : la récolte devient disponible après réception validée par l\'entrepôt.',
            'prix_unitaire.min' => 'Le prix au kilo doit être d\'au moins 1 FCFA.',
        ];
    }
}

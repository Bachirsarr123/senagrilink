<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStatutLivraisonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Le transporteur ne peut avancer que vers en_cours ou livree.
            // Le statut probleme est géré par signalerProbleme().
            'statut' => ['required', 'in:en_cours,livree'],
        ];
    }

    public function messages(): array
    {
        return [
            'statut.required' => 'Le statut est obligatoire.',
            'statut.in'       => 'Le statut doit être "en_cours" ou "livree".',
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValiderMarchandiseRequest extends FormRequest
{
    public const ETATS = ['bon', 'acceptable', 'abime'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantite_reelle'  => ['required', 'numeric', 'min:0.01'],
            // Contrôles de réception (étape 9) : identifiant du lot et état constaté
            'code_lot'         => ['required', 'string', 'max:50'],
            'etat_marchandise' => ['required', Rule::in(self::ETATS)],
            // Obligatoire si la quantité reçue diffère de la quantité prévue (vérifié par le contrôleur)
            'motif'            => ['nullable', 'string', 'max:500'],
            'observation'      => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantite_reelle.required' => 'La quantité vérifiée est obligatoire.',
            'quantite_reelle.min'      => 'La quantité doit être supérieure à zéro.',
            'code_lot.required'        => 'Saisissez le code du lot inscrit sur la marchandise.',
            'etat_marchandise.required' => 'Indiquez l\'état de la marchandise.',
            'etat_marchandise.in'      => 'État inconnu : bon, acceptable ou abîmé.',
        ];
    }
}

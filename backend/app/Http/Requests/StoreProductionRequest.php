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
            // Prix au kilo fixé par le producteur (FCFA), figé sur chaque commande.
            'prix_unitaire'     => ['required', 'numeric', 'min:1', 'max:10000000'],
            // R2 — une récolte est toujours créée « en_attente » : la disponibilité
            // résulte de la réception validée par l'entrepôt (validerMarchandise).
            'statut'            => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'statut.prohibited'         => 'Le statut est fixé par la plateforme : la récolte devient disponible après réception validée par l\'entrepôt.',
            'type_culture.required'     => 'Le type de culture est obligatoire.',
            'date_recolte.required'     => 'La date de récolte est obligatoire.',
            'date_recolte.date'         => 'La date de récolte n\'est pas valide.',
            'quantite_estimee.required' => 'La quantité estimée est obligatoire.',
            'prix_unitaire.required'    => 'Indiquez le prix de vente au kilo.',
            'prix_unitaire.min'         => 'Le prix au kilo doit être d\'au moins 1 FCFA.',
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Support\CircuitDepot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entrepot_id'       => ['required', 'integer', 'exists:entrepots,id'],
            // Toute marchandise déposée est un lot enregistré : le stock reste relié à
            // sa récolte, à son prix et à sa traçabilité.
            'production_id'     => ['required', 'integer', $this->regleProductionDuProducteur()],
            // Repris du lot s'il n'est pas précisé (pas de ressaisie).
            'produit'           => ['nullable', 'string', 'max:150'],
            'mode_transport'    => ['required', Rule::in(CircuitDepot::MODES)],
            'quantite_reservee' => ['required', 'numeric', 'min:0.01'],
            'date_debut'        => ['required', 'date'],
            'date_fin'          => ['nullable', 'date', 'after_or_equal:date_debut'],
        ];
    }

    /**
     * Un producteur ne peut rattacher que l'une de SES productions (V4).
     * Sans profil producteur (ex. administrateur), on garde la seule règle
     * d'existence : le contrôleur répond ensuite « Profil producteur introuvable ».
     */
    private function regleProductionDuProducteur(): Exists
    {
        $producteurId = $this->user()?->producteur?->id;

        $regle = Rule::exists('productions', 'id');

        // Une récolte annulée ne peut plus faire l'objet d'une réservation.
        $regle->whereNot('statut', 'annulee');

        return $producteurId === null ? $regle : $regle->where('producteur_id', $producteurId);
    }

    public function messages(): array
    {
        return [
            'entrepot_id.required'       => 'L\'entrepôt à réserver est obligatoire.',
            'entrepot_id.exists'         => 'L\'entrepôt sélectionné est introuvable.',
            'production_id.required'     => 'Choisissez la récolte (lot) à déposer.',
            'production_id.exists'       => 'La production référencée est introuvable (ou annulée) parmi vos productions.',
            'mode_transport.required'    => 'Choisissez qui transporte la marchandise : un transporteur, ou vous-même.',
            'mode_transport.in'          => 'Mode de transport inconnu.',
            'quantite_reservee.required' => 'La quantité à réserver est obligatoire.',
            'quantite_reservee.min'      => 'La quantité doit être supérieure à zéro.',
            'date_debut.required'        => 'La date de début de réservation est obligatoire.',
            'date_fin.after_or_equal'    => 'La date de fin doit être postérieure ou égale à la date de début.',
        ];
    }
}

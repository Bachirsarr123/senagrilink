<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'nom'       => ['sometimes', 'string', 'max:100'],
            'prenom'    => ['sometimes', 'string', 'max:100'],
            'email'     => ['sometimes', 'email', 'max:150', Rule::unique('utilisateurs', 'email')->ignore($userId)],
            'telephone' => ['nullable', 'string', 'max:20'],
            'adresse'   => ['nullable', 'string', 'max:255'],
            // Lot 12 : où recevoir son argent
            'moyen_paiement'  => ['nullable', 'in:wave,orange_money'],
            'numero_paiement' => ['nullable', 'string', 'regex:/^\+?[0-9 ]{9,15}$/'],
            'photo'     => ['nullable', 'string', 'max:255'],

            // P-17 — changement de mot de passe : le mot de passe actuel est exigé,
            // pour qu'un jeton dérobé ne suffise pas à s'approprier le compte.
            'mot_de_passe'        => ['nullable', 'string', 'min:8', 'confirmed'],
            'mot_de_passe_actuel' => ['required_with:mot_de_passe', 'nullable', 'string'],

            // Profil Producteur
            'superficie'     => ['nullable', 'numeric'],
            'types_cultures' => ['nullable', 'string', 'max:255'],
            'region'         => ['nullable', 'string', 'max:100'],

            // Profil Gestionnaire d'entrepôt
            'nom_entrepot' => ['nullable', 'string', 'max:150'],
            'capacite'     => ['nullable', 'numeric'],
            'localisation' => ['nullable', 'string', 'max:255'],
            // Lot 11 : réglages du pilote automatique (gestionnaire)
            'auto_accepter_depots'        => ['sometimes', 'boolean'],
            'auto_confirmer_commandes'    => ['sometimes', 'boolean'],
            'auto_affecter_transporteurs' => ['sometimes', 'boolean'],
            'delai_reponse_min'           => ['sometimes', 'integer', 'between:2,120'],
            'frais_stockage_kg'           => ['sometimes', 'numeric', 'between:0,1000'],

            // Position sur la carte (producteur, entrepôt, acheteur) : préremplit les missions
            'latitude'     => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude'    => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],

            // Profil Acheteur en gros
            'type_activite'        => ['nullable', 'string', 'max:150'],
            'volume_achat_mensuel' => ['nullable', 'numeric'],

            // Profil Transporteur
            'type_vehicule'   => ['nullable', 'string', 'max:100'],
            'capacite_charge' => ['nullable', 'numeric'],
            'zone'            => ['nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'numero_paiement.regex' => 'Le numéro Wave ou Orange Money doit être un numéro de téléphone (9 à 15 chiffres).',
            'mot_de_passe.min'                  => 'Le nouveau mot de passe doit contenir au moins 8 caractères.',
            'mot_de_passe.confirmed'            => 'La confirmation du nouveau mot de passe ne correspond pas.',
            'mot_de_passe_actuel.required_with' => 'Indiquez votre mot de passe actuel pour le modifier.',
        ];
    }
}

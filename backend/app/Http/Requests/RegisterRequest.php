<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom'        => ['required', 'string', 'max:100'],
            'prenom'     => ['required', 'string', 'max:100'],
            'email'      => ['required', 'email', 'max:150', 'unique:utilisateurs,email'],
            'telephone'  => ['nullable', 'string', 'max:20'],
            'adresse'    => ['nullable', 'string', 'max:255'],
            'mot_de_passe' => ['required', 'string', 'min:8', 'confirmed'],
            'photo'      => ['nullable', 'string', 'max:255'],
            'role'       => ['required', 'in:producteur,gestionnaire_entrepot,acheteur_gros,transporteur'],

            // Champs profil Producteur
            'superficie'      => ['required_if:role,producteur', 'nullable', 'numeric'],
            'types_cultures'  => ['required_if:role,producteur', 'nullable', 'string', 'max:255'],
            'region'          => ['required_if:role,producteur', 'nullable', 'string', 'max:100'],

            // Champs profil Gestionnaire d'entrepôt
            'nom_entrepot'    => ['required_if:role,gestionnaire_entrepot', 'nullable', 'string', 'max:150'],
            'capacite'        => ['required_if:role,gestionnaire_entrepot', 'nullable', 'numeric', 'min:0.01'],
            'localisation'    => ['required_if:role,gestionnaire_entrepot', 'nullable', 'string', 'max:255'],

            // Champs profil Acheteur en gros
            'type_activite'        => ['required_if:role,acheteur_gros', 'nullable', 'string', 'max:150'],
            'volume_achat_mensuel' => ['required_if:role,acheteur_gros', 'nullable', 'numeric'],

            // Champs profil Transporteur
            'type_vehicule'    => ['required_if:role,transporteur', 'nullable', 'string', 'max:100'],
            'capacite_charge'  => ['required_if:role,transporteur', 'nullable', 'numeric'],
            'zone'             => ['required_if:role,transporteur', 'nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'            => 'Cette adresse email est déjà utilisée.',
            'role.in'                 => 'Le rôle sélectionné est invalide.',
            'mot_de_passe.confirmed'  => 'La confirmation du mot de passe ne correspond pas.',

            'superficie.required_if'      => 'La superficie est obligatoire pour un producteur.',
            'types_cultures.required_if'  => 'Les types de cultures sont obligatoires pour un producteur.',
            'region.required_if'          => 'La région est obligatoire pour un producteur.',

            'nom_entrepot.required_if'    => 'Le nom de l\'entrepôt est obligatoire pour un gestionnaire.',
            'capacite.required_if'        => 'La capacité de l\'entrepôt est obligatoire pour un gestionnaire.',
            'localisation.required_if'    => 'La localisation de l\'entrepôt est obligatoire pour un gestionnaire.',

            'type_activite.required_if'         => 'Le type d\'activité est obligatoire pour un acheteur.',
            'volume_achat_mensuel.required_if'  => 'Le volume d\'achat mensuel est obligatoire pour un acheteur.',

            'type_vehicule.required_if'   => 'Le type de véhicule est obligatoire pour un transporteur.',
            'capacite_charge.required_if' => 'La capacité de charge est obligatoire pour un transporteur.',
            'zone.required_if'            => 'La zone de couverture est obligatoire pour un transporteur.',
        ];
    }
}

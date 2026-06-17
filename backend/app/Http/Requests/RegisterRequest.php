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
            'superficie'      => ['nullable', 'numeric'],
            'types_cultures'  => ['nullable', 'string', 'max:255'],
            'region'          => ['nullable', 'string', 'max:100'],

            // Champs profil Gestionnaire d'entrepôt
            'nom_entrepot'    => ['nullable', 'string', 'max:150'],
            'capacite'        => ['nullable', 'numeric'],
            'localisation'    => ['nullable', 'string', 'max:255'],

            // Champs profil Acheteur en gros
            'type_activite'        => ['nullable', 'string', 'max:150'],
            'volume_achat_mensuel' => ['nullable', 'numeric'],

            // Champs profil Transporteur
            'type_vehicule'    => ['nullable', 'string', 'max:100'],
            'capacite_charge'  => ['nullable', 'numeric'],
            'zone'             => ['nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'            => 'Cette adresse email est déjà utilisée.',
            'role.in'                 => 'Le rôle sélectionné est invalide.',
            'mot_de_passe.confirmed'  => 'La confirmation du mot de passe ne correspond pas.',
        ];
    }
}

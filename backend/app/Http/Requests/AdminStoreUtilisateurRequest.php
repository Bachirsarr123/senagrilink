<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminStoreUtilisateurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom'          => ['required', 'string', 'max:100'],
            'prenom'       => ['required', 'string', 'max:100'],
            'email'        => ['required', 'email', 'max:150', 'unique:utilisateurs,email'],
            'telephone'    => ['nullable', 'string', 'max:20'],
            'adresse'      => ['nullable', 'string', 'max:255'],
            'mot_de_passe' => ['required', 'string', 'min:8'],
            'photo'        => ['nullable', 'string', 'max:255'],
            // L'admin peut créer n'importe quel rôle, y compris administrateur
            'role'         => ['required', 'in:producteur,gestionnaire_entrepot,acheteur_gros,transporteur,administrateur'],

            // Profil Producteur
            'superficie'     => ['nullable', 'numeric'],
            'types_cultures' => ['nullable', 'string', 'max:255'],
            'region'         => ['nullable', 'string', 'max:100'],

            // Profil Gestionnaire d'entrepôt
            'nom_entrepot' => ['nullable', 'string', 'max:150'],
            'capacite'     => ['nullable', 'numeric'],
            'localisation' => ['nullable', 'string', 'max:255'],

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
            'email.unique' => 'Cette adresse email est déjà utilisée.',
            'role.in'      => 'Le rôle sélectionné est invalide.',
        ];
    }
}

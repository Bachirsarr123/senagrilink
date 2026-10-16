<?php

namespace Database\Factories;

use App\Models\AcheteurGros;
use App\Models\Entrepot;
use App\Models\Producteur;
use App\Models\Transporteur;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Utilisateur>
 */
class UserFactory extends Factory
{
    /**
     * Comme à l'inscription (AuthController::creerProfil), chaque compte métier
     * reçoit son profil : sans lui, les contrôleurs répondent « Profil introuvable ».
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Utilisateur $utilisateur) {
            match ($utilisateur->role) {
                'producteur' => Producteur::create([
                    'utilisateur_id' => $utilisateur->id,
                    'superficie'     => 2,
                    'types_cultures' => 'riz',
                    'region'         => 'Thiès',
                ]),
                'gestionnaire_entrepot' => Entrepot::create([
                    'utilisateur_id' => $utilisateur->id,
                    'nom_entrepot'   => 'Entrepôt test',
                    'capacite'       => 1000,
                    'localisation'   => 'Thiès',
                ]),
                'acheteur_gros' => AcheteurGros::create([
                    'utilisateur_id'       => $utilisateur->id,
                    'type_activite'        => 'grossiste',
                    'volume_achat_mensuel' => 500,
                ]),
                'transporteur' => Transporteur::create([
                    'utilisateur_id'  => $utilisateur->id,
                    'type_vehicule'   => 'camion',
                    'capacite_charge' => 5000,
                    'zone'            => 'Thiès',
                ]),
                default => null,
            };
        });
    }

    protected $model = Utilisateur::class;

    public function definition(): array
    {
        return [
            'nom'          => fake()->lastName(),
            'prenom'       => fake()->firstName(),
            'email'        => fake()->unique()->safeEmail(),
            'telephone'    => fake()->numerify('7########'),
            'adresse'      => fake()->address(),
            'mot_de_passe' => 'password',   // hashed automatiquement par le cast
            'role'         => 'producteur',
            'statut'       => 'actif',
        ];
    }

    public function gestionnaire(): static
    {
        return $this->state(['role' => 'gestionnaire_entrepot']);
    }

    public function acheteur(): static
    {
        return $this->state(['role' => 'acheteur_gros']);
    }

    public function transporteur(): static
    {
        return $this->state(['role' => 'transporteur']);
    }

    public function administrateur(): static
    {
        return $this->state(['role' => 'administrateur']);
    }

    public function bloque(): static
    {
        return $this->state(['statut' => 'bloque']);
    }
}

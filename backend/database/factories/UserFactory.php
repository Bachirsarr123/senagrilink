<?php

namespace Database\Factories;

use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Utilisateur>
 */
class UserFactory extends Factory
{
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

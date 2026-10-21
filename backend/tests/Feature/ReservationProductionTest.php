<?php

namespace Tests\Feature;

use App\Models\Production;
use App\Models\Reservation;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V4 — un producteur ne peut rattacher à sa réservation que SA production.
 */
class ReservationProductionTest extends TestCase
{
    use RefreshDatabase;

    private Utilisateur $producteur;
    private Utilisateur $autreProducteur;
    private Utilisateur $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->producteur      = Utilisateur::factory()->create();
        $this->autreProducteur = Utilisateur::factory()->create();
        $this->gestionnaire    = Utilisateur::factory()->gestionnaire()->create();
    }

    private function en(Utilisateur $utilisateur): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($utilisateur->createToken('test')->plainTextToken);
    }

    private function productionDe(Utilisateur $utilisateur): Production
    {
        return Production::create([
            'producteur_id'    => $utilisateur->producteur->id,
            'type_culture'     => 'riz',
            'date_recolte'     => '2026-06-01',
            'quantite_estimee' => 100,
        ]);
    }

    private function donnees(?int $productionId): array
    {
        return array_filter([
            'entrepot_id'       => $this->gestionnaire->entrepot->id,
            'production_id'     => $productionId,
            'produit'           => 'riz',
            'quantite_reservee' => 50,
            'date_debut'        => '2026-06-02',
            'mode_transport'    => 'transporteur',
        ], fn ($v) => $v !== null);
    }

    public function test_producteur_rattache_sa_propre_production(): void
    {
        $production = $this->productionDe($this->producteur);

        $this->en($this->producteur)
             ->postJson('/api/reservations', $this->donnees($production->id))
             ->assertStatus(201)
             ->assertJsonPath('reservation.production_id', $production->id);
    }

    public function test_production_dun_autre_producteur_refusee(): void
    {
        $productionAutrui = $this->productionDe($this->autreProducteur);

        $this->en($this->producteur)
             ->postJson('/api/reservations', $this->donnees($productionAutrui->id))
             ->assertStatus(422)
             ->assertJsonValidationErrors('production_id');

        $this->assertSame(0, Reservation::count());
    }

    public function test_meme_message_pour_un_lot_dautrui_et_un_lot_inexistant(): void
    {
        $productionAutrui = $this->productionDe($this->autreProducteur);

        $autrui = $this->en($this->producteur)
             ->postJson('/api/reservations', $this->donnees($productionAutrui->id))
             ->assertStatus(422)
             ->json('errors.production_id.0');

        $inexistant = $this->en($this->producteur)
             ->postJson('/api/reservations', $this->donnees(999999))
             ->assertStatus(422)
             ->json('errors.production_id.0');

        // Aucune information sur l'existence du lot d'un autre producteur
        $this->assertSame($inexistant, $autrui);
    }

    public function test_reservation_sans_production_refusee(): void
    {
        // Lot 1 : toute marchandise déposée est un lot enregistré (prix, traçabilité).
        $this->en($this->producteur)
             ->postJson('/api/reservations', $this->donnees(null))
             ->assertStatus(422)
             ->assertJsonValidationErrors('production_id');

        $this->assertSame(0, Reservation::count());
    }

    public function test_administrateur_sans_profil_producteur_comportement_inchange(): void
    {
        $admin      = Utilisateur::factory()->administrateur()->create();
        $production = $this->productionDe($this->producteur);

        // Comme avant le correctif : pas d'erreur de validation, le contrôleur
        // répond qu'il n'y a pas de profil producteur.
        $this->en($admin)
             ->postJson('/api/reservations', $this->donnees($production->id))
             ->assertStatus(404)
             ->assertJsonPath('message', 'Profil producteur introuvable.');

        $this->assertSame(0, Reservation::count());
    }

    public function test_autres_roles_toujours_refuses(): void
    {
        $this->en($this->gestionnaire)
             ->postJson('/api/reservations', $this->donnees(null))
             ->assertStatus(403);
    }
}

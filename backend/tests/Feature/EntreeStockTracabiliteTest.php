<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Stock;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * R1 — une entrée de stock manuelle ne peut citer qu'un lot réceptionné
 * (réservation « enregistree ») dans l'entrepôt du gestionnaire.
 */
class EntreeStockTracabiliteTest extends TestCase
{
    use ConstruitParcours, RefreshDatabase;

    private Utilisateur $producteur;
    private Utilisateur $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->producteur   = Utilisateur::factory()->create();
        $this->gestionnaire = Utilisateur::factory()->gestionnaire()->create();
    }

    private function entree(Utilisateur $gestionnaire, ?int $productionId)
    {
        return $this->en($gestionnaire)->postJson('/api/stocks/entree', array_filter([
            'produit'       => 'riz',
            'quantite'      => 5,
            'production_id' => $productionId,
        ], fn ($v) => $v !== null));
    }

    public function test_lot_receptionne_dans_cet_entrepot_accepte(): void
    {
        $production = $this->production($this->producteur);
        $this->reservation($production, $this->gestionnaire, 'enregistree');

        $this->entree($this->gestionnaire, $production->id)
             ->assertStatus(201)
             ->assertJsonPath('stock.production_id', $production->id);
    }

    public function test_lot_receptionne_dans_un_autre_entrepot_refuse(): void
    {
        $production = $this->production($this->producteur);
        $this->reservation($production, $this->gestionnaire, 'enregistree');
        $autreGestionnaire = Utilisateur::factory()->gestionnaire()->create();

        $this->entree($autreGestionnaire, $production->id)
             ->assertStatus(422)
             ->assertJsonValidationErrors('production_id');

        $this->assertSame(0, Stock::count());
    }

    public function test_lot_dont_la_reception_nest_pas_validee_refuse(): void
    {
        $production = $this->production($this->producteur);

        foreach (['en_attente', 'confirmee', 'arrivee_entrepot', 'refusee', 'annulee'] as $statut) {
            $reservation = $this->reservation($production, $this->gestionnaire, $statut);

            $this->entree($this->gestionnaire, $production->id)->assertStatus(422);

            $reservation->delete();
        }

        $this->assertSame(0, Stock::count());
    }

    public function test_lot_sans_reservation_refuse(): void
    {
        $production = $this->production($this->producteur);

        $this->entree($this->gestionnaire, $production->id)->assertStatus(422);
    }

    public function test_reservation_falsifiee_avec_le_lot_dun_autre_producteur_refusee(): void
    {
        // Donnée antérieure au correctif V4 : réservation d'un producteur citant le lot d'un autre
        $production = $this->production($this->producteur);
        $fraudeur   = Utilisateur::factory()->create();
        Reservation::create([
            'entrepot_id'       => $this->gestionnaire->entrepot->id,
            'producteur_id'     => $fraudeur->producteur->id,
            'production_id'     => $production->id,
            'produit'           => 'riz',
            'quantite_reservee' => 10,
            'date_debut'        => '2026-06-02',
            'statut'            => 'enregistree',
        ]);

        $this->entree($this->gestionnaire, $production->id)->assertStatus(422);
    }

    public function test_meme_message_pour_un_lot_inexistant_et_un_lot_non_receptionne(): void
    {
        $production = $this->production($this->producteur);

        $nonReceptionne = $this->entree($this->gestionnaire, $production->id)->json('errors.production_id.0');
        $inexistant     = $this->entree($this->gestionnaire, 999999)->json('errors.production_id.0');

        $this->assertNotNull($inexistant);
        $this->assertSame($inexistant, $nonReceptionne);
    }

    public function test_entree_manuelle_sans_lot_toujours_possible(): void
    {
        $this->entree($this->gestionnaire, null)
             ->assertStatus(201)
             ->assertJsonPath('stock.production_id', null);
    }

    public function test_administrateur_comportement_inchange(): void
    {
        $admin = Utilisateur::factory()->administrateur()->create();

        $this->entree($admin, null)->assertStatus(404);
    }
}

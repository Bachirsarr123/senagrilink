<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\MouvementStock;
use App\Models\Production;
use App\Models\Stock;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * Lot 1 — stock physique / réservé / disponible, journal des mouvements,
 * entrée uniquement par réception, ajustements motivés.
 */
class FondationsStockTest extends TestCase
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

    /** Lot réceptionné par l'API (arrivée signalée puis validation) : 60 kg en stock. */
    private function lotReceptionne(float $quantiteRecue = 60): Stock
    {
        $production  = $this->production($this->producteur);
        $reservation = $this->reservation($production, $this->gestionnaire, 'arrivee_entrepot');

        $id = $this->receptionner($this->gestionnaire, $reservation->id, $quantiteRecue)
                   ->assertStatus(201)
                   ->json('stock.id');

        return Stock::findOrFail($id);
    }

    private function ajuster(Stock $stock, array $corps, ?Utilisateur $auteur = null)
    {
        return $this->en($auteur ?? $this->gestionnaire)->postJson("/api/stocks/{$stock->id}/ajustement", $corps);
    }

    public function test_plus_aucune_entree_ni_sortie_libre(): void
    {
        $this->en($this->gestionnaire)->postJson('/api/stocks/entree', ['produit' => 'riz', 'quantite' => 5])->assertNotFound();
        $this->en($this->gestionnaire)->postJson('/api/stocks/sortie', ['stock_id' => 1, 'quantite_sortie' => 5])->assertNotFound();
        $this->assertSame(0, Stock::count());
    }

    public function test_reception_cree_le_stock_et_son_mouvement_dentree(): void
    {
        $stock = $this->lotReceptionne(60);

        $this->assertEquals(60, $stock->quantite);
        $this->assertEquals(0, $stock->quantite_reservee);
        $mouvement = $stock->mouvements()->sole();
        $this->assertSame('entree', $mouvement->type);
        $this->assertSame('reservation', $mouvement->operation_type);
        $this->assertSame($this->gestionnaire->id, $mouvement->utilisateur_id);

        $production = $stock->production;
        $this->assertSame('disponible', $production->statut);
        $this->assertEquals(60, $production->quantite_reelle); // quantité réellement reçue
    }

    public function test_reservation_sans_lot_refusee(): void
    {
        $this->en($this->producteur)->postJson('/api/reservations', [
            'entrepot_id' => $this->gestionnaire->entrepot->id, 'produit' => 'riz', 'mode_transport' => 'transporteur',
            'quantite_reservee' => 10, 'date_debut' => '2026-06-02',
        ])->assertStatus(422)->assertJsonValidationErrors('production_id');
    }

    public function test_retrait_motive_dans_la_limite_du_disponible(): void
    {
        $stock = $this->lotReceptionne(60);
        $this->commande(Utilisateur::factory()->acheteur()->create(), $stock, 'confirmee'); // 10 réservés

        // 55 > disponible (50) : une quantité promise ne peut pas être retirée
        $this->ajuster($stock, ['motif_type' => 'perte', 'sens' => 'retrait', 'quantite' => 55, 'motif' => 'Rongeurs'])
             ->assertStatus(422)->assertJsonPath('quantite_disponible', 50);

        $this->ajuster($stock, ['motif_type' => 'avarie', 'sens' => 'retrait', 'quantite' => 50, 'motif' => 'Humidité'])
             ->assertOk()->assertJsonPath('stock.quantite', 10)->assertJsonPath('stock.quantite_disponible', 0);

        $dernier = MouvementStock::latest('id')->first();
        $this->assertSame(['ajustement', 'avarie', -50.0, 'Humidité'], [$dernier->type, $dernier->motif_type, $dernier->variation_physique, $dernier->motif]);
    }

    public function test_ajustement_exige_une_raison_connue_et_un_motif(): void
    {
        $stock = $this->lotReceptionne();

        $this->ajuster($stock, ['motif_type' => 'vente', 'sens' => 'retrait', 'quantite' => 1, 'motif' => 'x'])->assertJsonValidationErrors('motif_type');
        $this->ajuster($stock, ['motif_type' => 'perte', 'sens' => 'retrait', 'quantite' => 1])->assertJsonValidationErrors('motif');
        $this->ajuster($stock, ['motif_type' => 'perte', 'sens' => 'ajout', 'quantite' => 1, 'motif' => 'x'])->assertStatus(422);

        $this->ajuster($stock, ['motif_type' => 'correction_inventaire', 'sens' => 'ajout', 'quantite' => 2.5, 'motif' => 'Recomptage'])
             ->assertOk()->assertJsonPath('stock.quantite', 62.5);
    }

    public function test_un_stock_epuise_par_ajustement_puis_corrige_redevient_disponible(): void
    {
        $stock = $this->lotReceptionne(5);

        $this->ajuster($stock, ['motif_type' => 'retrait_producteur', 'sens' => 'retrait', 'quantite' => 5, 'motif' => 'Repris par le producteur'])
             ->assertOk()->assertJsonPath('stock.statut', 'epuise');
        $this->ajuster($stock, ['motif_type' => 'correction_inventaire', 'sens' => 'ajout', 'quantite' => 1, 'motif' => 'Sac oublié'])
             ->assertOk()->assertJsonPath('stock.statut', 'disponible');
    }

    public function test_perimetre_des_ajustements_du_seuil_et_du_journal(): void
    {
        $stock = $this->lotReceptionne();
        $autre = Utilisateur::factory()->gestionnaire()->create();

        $this->ajuster($stock, ['motif_type' => 'perte', 'sens' => 'retrait', 'quantite' => 1, 'motif' => 'x'], $autre)->assertNotFound();
        $this->en($autre)->putJson("/api/stocks/{$stock->id}/seuil", ['seuil_alerte' => 5])->assertNotFound();
        $this->en($autre)->getJson("/api/stocks/{$stock->id}/mouvements")->assertNotFound();
        $this->en(Utilisateur::factory()->acheteur()->create())->getJson("/api/stocks/{$stock->id}/mouvements")->assertForbidden();

        $this->en(Utilisateur::factory()->administrateur()->create())->getJson("/api/stocks/{$stock->id}/mouvements")
             ->assertOk()->assertJsonCount(1, 'mouvements');
        $this->assertEquals(60, $stock->fresh()->quantite);
    }

    public function test_seuil_modifiable_et_alerte_sur_le_disponible(): void
    {
        $stock = $this->lotReceptionne(60);
        $this->en($this->gestionnaire)->putJson("/api/stocks/{$stock->id}/seuil", ['seuil_alerte' => 55])
             ->assertOk()->assertJsonPath('stock.seuil_alerte', 55);

        $this->en($this->gestionnaire)->getJson('/api/stocks/alertes')->assertOk()->assertJsonPath('total', 0);

        // 10 réservés : disponible 50 <= seuil 55, alors que le physique (60) est au-dessus
        $this->commande(Utilisateur::factory()->acheteur()->create(), $stock, 'confirmee');
        $this->en($this->gestionnaire)->getJson('/api/stocks/alertes')->assertOk()->assertJsonPath('total', 1);

        $this->en($this->gestionnaire)->putJson("/api/stocks/{$stock->id}/seuil", ['seuil_alerte' => null])->assertOk();
        $this->assertNull($stock->fresh()->seuil_alerte);
    }

    public function test_parcours_complet_le_stock_nest_deduit_quune_fois(): void
    {
        $stock    = $this->lotReceptionne(60);
        $acheteur = Utilisateur::factory()->acheteur()->create();
        $transporteur = Utilisateur::factory()->transporteur()->create();

        $commandeId = $this->en($acheteur)->postJson('/api/commandes', ['stock_id' => $stock->id, 'quantite' => 20])
                           ->assertStatus(201)->json('commande.id');
        $this->en($this->gestionnaire)->putJson("/api/commandes/{$commandeId}/confirmer")->assertOk();
        $this->assertEquals([60.0, 20.0], [$stock->fresh()->quantite, $stock->fresh()->quantite_reservee]);

        $livraisonId = $this->en($this->gestionnaire)->postJson('/api/livraisons', [
            'commande_id' => $commandeId, 'transporteur_id' => $transporteur->transporteur->id,
            'origine' => 'Entrepôt', 'destination' => 'Dakar',
        ])->assertStatus(201)->json('livraison.id');

        // Départ : sortie physique de la quantité réservée
        $this->en($transporteur)->putJson("/api/livraisons/{$livraisonId}/statut", ['statut' => 'en_cours'])->assertOk();
        $this->assertEquals([40.0, 0.0], [$stock->fresh()->quantite, $stock->fresh()->quantite_reservee]);
        $this->assertSame('sorti', Commande::find($commandeId)->etat_stock);

        // Rejeu du départ : refusé, rien n'est déduit une seconde fois
        $this->en($transporteur)->putJson("/api/livraisons/{$livraisonId}/statut", ['statut' => 'en_cours'])->assertStatus(422);
        $this->en($transporteur)->putJson("/api/livraisons/{$livraisonId}/statut", ['statut' => 'livree'])->assertOk();

        $this->assertEquals(40, $stock->fresh()->quantite);
        $this->assertSame('livree', Commande::find($commandeId)->statut);
        $this->assertSame(['entree', 'reservation', 'sortie'], $stock->mouvements()->pluck('type')->all());
        $this->assertSame(
            ['creation', 'confirmation', 'livraison'],
            Commande::find($commandeId)->historique()->pluck('action')->all(),
        );
    }

    public function test_journal_coherent_avec_le_stock(): void
    {
        $stock = $this->lotReceptionne(60);
        $this->commande(Utilisateur::factory()->acheteur()->create(), $stock, 'en_attente');
        $this->ajuster($stock, ['motif_type' => 'perte', 'sens' => 'retrait', 'quantite' => 3, 'motif' => 'x'])->assertOk();

        $mouvements = $stock->mouvements()->get();
        $this->assertEquals($stock->fresh()->quantite, $mouvements->sum('variation_physique'));
        $this->assertEquals($stock->fresh()->quantite, $mouvements->last()->physique_apres);
    }
}

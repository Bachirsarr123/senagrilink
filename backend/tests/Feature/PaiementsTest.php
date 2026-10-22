<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Versement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitLivraison;
use Tests\TestCase;

/**
 * Lot 12 : paiements simulés comme des vrais. L'acheteur paie marchandise et
 * livraison ; l'argent est mis de côté jusqu'à la réception, puis versé au
 * producteur (moins frais d'entrepôt et commission), à l'entrepôt, au
 * transporteur et à SenAgriLink ; remboursé si la commande est annulée ;
 * bloqué en cas de litige jusqu'à la décision de l'administrateur.
 */
class PaiementsTest extends TestCase
{
    use ConstruitLivraison, RefreshDatabase;

    private \App\Models\Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparerLivraison();
        // Entrepôt à Thiès, acheteur ~ 5 km plus loin : 7 km par la route (× 1,3)
        $this->gestionnaire->entrepot->update(['latitude' => 14.79, 'longitude' => -16.93, 'frais_stockage_kg' => 5]);
        $this->acheteur->acheteurGros->update(['latitude' => 14.79, 'longitude' => -16.88]);
        $this->producteur->update(['moyen_paiement' => 'wave', 'numero_paiement' => '771112233']);
        $this->admin = \App\Models\Utilisateur::factory()->administrateur()->create();
    }

    /** Lot 13 : commander et payer d'un seul geste. Renvoie [commande, référence du paiement]. */
    private function commander(string $moyen = 'wave', float $quantite = 10): array
    {
        $r = $this->en($this->acheteur)->postJson('/api/commandes', ['stock_id' => $this->stock->id, 'quantite' => $quantite, 'moyen' => $moyen])->assertStatus(201);

        return [Commande::find($r->json('commande.id')), $r->json('paiement.reference'), $r];
    }

    private function confirmer(Commande $c)
    {
        return $this->en($this->gestionnaire)->putJson("/api/commandes/{$c->id}/confirmer");
    }

    private function payer(array $ids, string $moyen = 'wave')
    {
        return $this->en($this->acheteur)->postJson('/api/paiements', ['commandes' => $ids, 'moyen' => $moyen]);
    }

    private function simuler(string $ref, string $resultat = 'reussi')
    {
        return $this->en($this->acheteur)->postJson("/api/paiements/{$ref}/simulation", ['resultat' => $resultat]);
    }

    private function argent($u)
    {
        return $this->en($u)->getJson('/api/argent')->assertOk()->json();
    }

    public function test_montants_figes_a_la_commande_et_devis(): void
    {
        $devis = $this->en($this->acheteur)->getJson("/api/commandes/devis?stock_id={$this->stock->id}&quantite=10")->assertOk()->json('devis');
        $c = Commande::find($this->en($this->acheteur)->postJson('/api/commandes', ['stock_id' => $this->stock->id, 'quantite' => 10])->json('commande.id'));

        // 10 kg × 150 = 1 500 ; livraison 500 + 100 × 7 km = 1 200 ; commission 2 % = 30 ; entrepôt 5 × 10 = 50
        $this->assertSame([1500.0, 1200.0, 30.0, 50.0, 1420.0, 2700.0],
            [$c->prix, $c->frais_livraison, $c->commission, $c->frais_stockage, $c->montant_producteur, $c->total_a_payer]);
        $this->assertEquals([$c->total_a_payer, $c->montant_producteur], [$devis['total_a_payer'], $devis['montant_producteur']]);
        $this->assertSame('a_payer', $c->statut_paiement);
    }

    public function test_paiement_mis_de_cote_puis_verse_a_chacun_a_la_reception(): void
    {
        [$c, $ref, $r] = $this->commander('wave');
        $p = $r->json('paiement');
        $this->assertSame(['initie', 'simulation', 2700], [$p['statut'], $p['fournisseur'], (int) $p['montant']]);
        $this->assertStringContainsString('Confirmez le paiement de 2 700 FCFA avec Wave', $r->json('message'));
        // Pas encore payée : quantité gardée pour l'acheteur, l'entrepôt ne peut pas la confirmer
        $this->assertSame(['en_attente', 'reserve'], [$c->statut, $c->etat_stock]);
        $this->assertEquals(10, $this->stock->fresh()->quantite_reservee);
        $this->confirmer($c)->assertStatus(422)->assertJsonPath('message', 'L\'acheteur n\'a pas encore payé : la commande sera à confirmer dès son paiement.');
        $this->en($this->acheteur)->putJson("/api/commandes/{$c->id}", ['quantite' => 5])->assertStatus(422);

        $this->simuler($p['reference'])->assertOk()->assertJsonPath('paiement.statut', 'reussi');
        $this->simuler($p['reference'])->assertStatus(422); // pas deux fois
        $this->payer([$c->id])->assertStatus(422);           // déjà payée
        $this->assertSame('paye', $c->fresh()->statut_paiement);
        $this->confirmer($c)->assertOk();
        $this->assertEquals(10, $this->stock->fresh()->quantite_reservee); // gardée une seule fois
        $this->assertEquals(1420.0, $this->argent($this->producteur)['totaux']['mis_de_cote']);
        $this->assertTrue($this->producteur->fresh()->notifications->contains(fn ($n) => str_contains($n->data['message'] ?? '', '1 420 FCFA vous seront versés')));
        // Commande payée : plus modifiable
        $this->en($this->acheteur)->putJson("/api/commandes/{$c->id}", ['quantite' => 5])->assertStatus(422);

        // Livraison jusqu'à la remise avec le code
        foreach (['prete', 'organisee', 'acceptee', 'chargee', 'en_cours', 'arrivee'] as $e) {
            $this->etapeVers($c->id, $e);
        }
        $this->assertEquals(1200.0, $this->argent($this->transporteurA)['totaux']['mis_de_cote']);
        $l = $c->fresh()->livraison;
        $this->en($this->transporteurA)->putJson("/api/livraisons/{$l->id}/remise", ['code' => $c->fresh()->code_reception])->assertOk();

        $this->assertSame('libere', $c->fresh()->statut_paiement);
        $this->assertEquals(['mis_de_cote' => 0, 'verse' => 1420], array_intersect_key($this->argent($this->producteur)['totaux'], ['mis_de_cote' => 1, 'verse' => 1]));
        $this->assertEquals(1200.0, $this->argent($this->transporteurA)['totaux']['verse']);
        $this->assertEquals(50.0, $this->argent($this->gestionnaire)['totaux']['verse']);
        $this->assertSame(30.0, (float) Versement::where('beneficiaire', 'plateforme')->sum('montant'));
        $v = Versement::where('beneficiaire', 'producteur')->first();
        $this->assertSame(['wave', '771112233'], [$v->moyen, $v->telephone]);
        $this->assertTrue($this->producteur->fresh()->notifications->contains(fn ($n) => str_contains($n->data['message'] ?? '', '1 420 FCFA versés sur votre Wave 771112233')));
    }

    public function test_annulation_rembourse_l_acheteur(): void
    {
        [$c, $ref] = $this->commander('wave');
        $this->simuler($ref);
        $this->confirmer($c)->assertOk();
        $this->en($this->gestionnaire)->putJson("/api/commandes/{$c->id}/annuler-confirmation", ['motif' => 'Stock abîmé'])->assertOk();

        $this->assertSame('rembourse', $c->fresh()->statut_paiement);
        $this->assertSame('rembourse', $c->fresh()->paiement->statut);
        $this->assertEquals(0.0, $this->argent($this->producteur)['totaux']['mis_de_cote']);
        $this->assertTrue($this->acheteur->fresh()->notifications->contains(fn ($n) => str_contains($n->data['message'] ?? '', '2 700 FCFA remboursés sur votre Wave')));
    }

    public function test_paiement_en_especes_a_la_livraison(): void
    {
        [$c, , $r] = $this->commander('especes');
        $r->assertJsonPath('paiement.fournisseur', 'livraison');
        $this->assertStringContainsString('Vous paierez 2 700 FCFA en espèces au transporteur', $r->json('message'));
        $this->assertSame('a_la_livraison', $c->fresh()->statut_paiement);
        $this->confirmer($c)->assertOk(); // rien à attendre : l'entrepôt la traite tout de suite
        foreach (['prete', 'organisee', 'acceptee', 'chargee', 'en_cours', 'arrivee'] as $e) {
            $this->etapeVers($c->id, $e);
        }
        $this->en($this->transporteurA)->putJson("/api/livraisons/{$c->fresh()->livraison->id}/remise", ['code' => $c->fresh()->code_reception])->assertOk();

        $this->assertSame('encaisse', $c->fresh()->statut_paiement);
        $this->assertEquals(1420.0, $this->argent($this->producteur)['totaux']['a_regler']); // à recevoir du transporteur
        $this->assertEquals(1200.0, $this->argent($this->transporteurA)['totaux']['regle']); // gardé par le transporteur
    }

    public function test_litige_bloque_l_argent_jusqu_a_la_decision(): void
    {
        [$c, $ref] = $this->commander('orange_money');
        $this->simuler($ref);
        $this->confirmer($c)->assertOk();
        foreach (['prete', 'organisee', 'acceptee', 'chargee', 'en_cours', 'arrivee'] as $e) {
            $this->etapeVers($c->id, $e);
        }
        $this->en($this->acheteur)->postJson("/api/commandes/{$c->id}/anomalie", ['type' => 'quantite_manquante', 'description' => 'Il manque 2 kg'])->assertStatus(201);
        $this->assertSame('litige', $c->fresh()->statut_paiement);
        $this->assertEquals(1420.0, $this->argent($this->producteur)['totaux']['mis_de_cote']);
        $this->assertSame([$c->id], array_column($this->en($this->admin)->getJson('/api/admin/paiements')->json('litiges'), 'id'));

        $this->en($this->admin)->putJson("/api/admin/commandes/{$c->id}/paiement", ['decision' => 'liberer'])->assertOk();
        $this->assertEquals(1420.0, $this->argent($this->producteur)['totaux']['verse']);
    }

    public function test_paiement_echoue_et_controles(): void
    {
        [$c, $ref] = $this->commander('wave');
        $this->simuler($ref, 'echoue')->assertOk()->assertJsonPath('paiement.statut', 'echoue');
        $this->assertNull($c->fresh()->paiement_id);
        $this->payer([$c->id])->assertStatus(201); // on peut réessayer depuis « Mes commandes »

        // Un autre acheteur ne paie ni ne voit les commandes d'autrui
        $autre = \App\Models\Utilisateur::factory()->acheteur()->create();
        $this->en($autre)->postJson('/api/paiements', ['commandes' => [$c->id], 'moyen' => 'wave'])->assertStatus(404);
        $this->en($autre)->getJson("/api/paiements/{$ref}")->assertStatus(404);
        $this->en($this->acheteur)->postJson('/api/paiements', ['commandes' => [$c->id], 'moyen' => 'bitcoin'])->assertStatus(422);
        $this->en($this->acheteur)->postJson('/api/commandes', ['stock_id' => $this->stock->id, 'quantite' => 1, 'moyen' => 'bitcoin'])->assertStatus(422);
        $this->en($this->producteur)->putJson('/api/profile', ['numero_paiement' => 'abc'])->assertStatus(422);
    }

    /** Lot 13 : paiement abandonné, commande annulée après 30 minutes, quantité rendue au stock. */
    public function test_commande_non_payee_annulee_apres_le_delai(): void
    {
        [$c, $ref] = $this->commander('wave');
        [$payee, $refPayee] = $this->commander('wave', 5);
        $this->simuler($refPayee);
        $this->assertEquals(15, $this->stock->fresh()->quantite_reservee);

        $this->travel(29)->minutes();
        $this->assertSame(0, app(\App\Services\Paiements::class)->expirer());
        $this->travel(2)->minutes();
        $this->assertSame(1, app(\App\Services\Paiements::class)->expirer());

        $this->assertSame(['annulee', 'libere'], [$c->fresh()->statut, $c->fresh()->etat_stock]);
        $this->assertSame('en_attente', $payee->fresh()->statut); // payée : jamais annulée
        $this->assertEquals(5, $this->stock->fresh()->quantite_reservee);
        $this->simuler($ref)->assertStatus(422); // paiement trop tard refusé
        $this->assertTrue($this->acheteur->fresh()->notifications->contains(fn ($n) => str_contains($n->data['message'] ?? '', 'paiement non reçu dans les 30 minutes')));
    }

    /** Lot 13 : l'acheteur annule pendant le paiement, la quantité revient au stock. */
    public function test_annulation_pendant_le_paiement_rend_la_quantite(): void
    {
        [$c] = $this->commander('wave');
        $this->en($this->acheteur)->deleteJson("/api/commandes/{$c->id}")->assertOk();
        $this->assertEquals(0, $this->stock->fresh()->quantite_reservee);
        $this->assertSame('libere', $c->fresh()->etat_stock);
    }

    /** Étape de la commande (lot 3), une à la fois. */
    private function etapeVers(int $id, string $e): void
    {
        match ($e) {
            'prete' => [$this->en($this->gestionnaire)->putJson("/api/commandes/{$id}/preparer")->assertOk(), $this->en($this->gestionnaire)->putJson("/api/commandes/{$id}/prete")->assertOk()],
            'organisee' => $this->en($this->gestionnaire)->postJson('/api/livraisons', ['commande_id' => $id, 'transporteur_id' => $this->transporteurA->transporteur->id])->assertStatus(201),
            'acceptee' => $this->en($this->transporteurA)->putJson("/api/livraisons/{$this->livraisonId($id)}/accepter-mission")->assertOk(),
            default => $this->etapeLivraison($this->transporteurA, $id, $e)->assertOk(),
        };
    }
}

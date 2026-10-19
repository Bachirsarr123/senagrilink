<?php

namespace Tests\Feature;

use App\Models\Livraison;
use App\Models\Stock;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * Annulations et corrections des commandes, livraisons et récoltes : transitions
 * autorisées, cohérence du stock, permissions, historique, double annulation.
 */
class AnnulationOperationsTest extends TestCase
{
    use ConstruitParcours, RefreshDatabase;

    private function stockEtCommande(): object
    {
        $p = (object) [
            'producteur'   => Utilisateur::factory()->create(),
            'gestionnaire' => Utilisateur::factory()->gestionnaire()->create(),
            'acheteur'     => Utilisateur::factory()->acheteur()->create(),
        ];
        $p->stock = Stock::create([
            'entrepot_id' => $p->gestionnaire->entrepot->id, 'production_id' => $this->production($p->producteur)->id,
            'produit' => 'riz', 'quantite' => 10, 'statut' => 'disponible',
        ]);
        $p->commande = $this->commande($p->acheteur, $p->stock); // 10 kg, en_attente

        return $p;
    }

    public function test_refus_dune_commande_en_attente_par_lentrepot(): void
    {
        $p = $this->stockEtCommande();

        $this->en($p->gestionnaire)->putJson("/api/commandes/{$p->commande->id}/refuser")->assertStatus(422)->assertJsonValidationErrors('motif');
        $this->en(Utilisateur::factory()->gestionnaire()->create())->putJson("/api/commandes/{$p->commande->id}/refuser", ['motif' => 'x'])->assertStatus(403);
        $this->en($p->acheteur)->putJson("/api/commandes/{$p->commande->id}/refuser", ['motif' => 'x'])->assertStatus(403);

        $this->en($p->gestionnaire)->putJson("/api/commandes/{$p->commande->id}/refuser", ['motif' => 'Qualité insuffisante'])->assertOk()
             ->assertJsonPath('commande.statut', 'annulee');
        $this->en($p->gestionnaire)->putJson("/api/commandes/{$p->commande->id}/refuser", ['motif' => 'x'])->assertStatus(422);

        $this->assertSame(10.0, (float) $p->stock->fresh()->quantite);
        $this->assertDatabaseHas('historique_operations', ['sujet_type' => 'commande', 'sujet_id' => $p->commande->id, 'action' => 'refus', 'utilisateur_id' => $p->gestionnaire->id]);
        $this->assertContains('commande_refus', $this->en($p->acheteur)->getJson('/api/notifications')->json('notifications.*.data.type'));
    }

    public function test_annulation_dune_confirmation_remet_la_quantite_en_stock(): void
    {
        $p = $this->stockEtCommande();
        $this->en($p->gestionnaire)->putJson("/api/commandes/{$p->commande->id}/confirmer")->assertOk();
        $this->assertSame('epuise', $p->stock->fresh()->statut);

        // L'acheteur ne peut plus annuler une commande confirmée
        $this->en($p->acheteur)->deleteJson("/api/commandes/{$p->commande->id}")->assertStatus(422);

        $this->en($p->gestionnaire)->putJson("/api/commandes/{$p->commande->id}/annuler-confirmation", ['motif' => 'Confirmée par erreur'])->assertOk()
             ->assertJsonPath('commande.statut', 'annulee');

        $stock = $p->stock->fresh();
        $this->assertSame(10.0, (float) $stock->quantite);
        $this->assertSame('disponible', $stock->statut);
        $this->assertNull($stock->date_sortie);

        // Double annulation : refusée, le stock n'est pas recrédité deux fois
        $this->en($p->gestionnaire)->putJson("/api/commandes/{$p->commande->id}/annuler-confirmation", ['motif' => 'x'])->assertStatus(422);
        $this->assertSame(10.0, (float) $p->stock->fresh()->quantite);
    }

    public function test_commande_avec_livraison_non_annulable(): void
    {
        $p = $this->parcours('en_attente');

        $this->en($p->gestionnaire)->putJson("/api/commandes/{$p->commande->id}/annuler-confirmation", ['motif' => 'x'])->assertStatus(422);
        $this->assertSame('confirmee', $p->commande->fresh()->statut);
    }

    public function test_annulation_par_lacheteur_historisee(): void
    {
        $p = $this->stockEtCommande();

        $this->en($p->acheteur)->deleteJson("/api/commandes/{$p->commande->id}")->assertOk()->assertJsonPath('commande.statut', 'annulee');
        $this->en($p->acheteur)->deleteJson("/api/commandes/{$p->commande->id}")->assertStatus(422);
        $this->assertDatabaseHas('historique_operations', ['sujet_type' => 'commande', 'action' => 'annulation', 'utilisateur_id' => $p->acheteur->id]);
    }

    public function test_changement_de_transporteur_dune_livraison_non_demarree(): void
    {
        $p      = $this->parcours('en_attente');
        $nouveau = Utilisateur::factory()->transporteur()->create();
        $url    = "/api/livraisons/{$p->livraison->id}/transporteur";

        $this->en(Utilisateur::factory()->gestionnaire()->create())->putJson($url, ['transporteur_id' => $nouveau->transporteur->id])->assertStatus(403);

        $this->en($p->gestionnaire)->putJson($url, ['transporteur_id' => $nouveau->transporteur->id, 'motif' => 'Véhicule en panne'])->assertOk()
             ->assertJsonPath('livraison.transporteur_id', $nouveau->transporteur->id);

        $this->assertSame([], $this->en($p->transporteur)->getJson('/api/livraisons')->json('livraisons'));
        $this->assertSame([$p->livraison->id], $this->en($nouveau)->getJson('/api/livraisons')->json('livraisons.*.id'));
        $this->assertContains('mission_retiree', $this->en($p->transporteur)->getJson('/api/notifications')->json('notifications.*.data.type'));
        $this->assertContains('mission_assignee', $this->en($nouveau)->getJson('/api/notifications')->json('notifications.*.data.type'));
        $this->assertDatabaseHas('historique_operations', ['sujet_type' => 'livraison', 'sujet_id' => $p->livraison->id, 'action' => 'reassignation_transporteur', 'motif' => 'Véhicule en panne']);
    }

    public function test_livraison_demarree_ou_terminee_non_reassignable(): void
    {
        foreach (['en_cours', 'livree', 'probleme'] as $statut) {
            $p = $this->parcours($statut);
            $autre = Utilisateur::factory()->transporteur()->create();

            $this->en($p->gestionnaire)->putJson("/api/livraisons/{$p->livraison->id}/transporteur", ['transporteur_id' => $autre->transporteur->id])
                 ->assertStatus(422);
            $this->assertSame($p->transporteur->transporteur->id, Livraison::find($p->livraison->id)->transporteur_id);
        }
    }

    public function test_creation_dune_livraison_notifie_le_transporteur(): void
    {
        $p = $this->stockEtCommande();
        $t = Utilisateur::factory()->transporteur()->create();
        $this->en($p->gestionnaire)->putJson("/api/commandes/{$p->commande->id}/confirmer")->assertOk();

        $this->en($p->gestionnaire)->postJson('/api/livraisons', [
            'commande_id' => $p->commande->id, 'transporteur_id' => $t->transporteur->id,
            'origine' => 'Entrepôt', 'destination' => 'Dakar',
        ])->assertStatus(201);

        $this->assertContains('mission_assignee', $this->en($t)->getJson('/api/notifications')->json('notifications.*.data.type'));
    }

    public function test_annulation_dune_recolte(): void
    {
        $producteur   = Utilisateur::factory()->create();
        $gestionnaire = Utilisateur::factory()->gestionnaire()->create();
        $libre        = $this->production($producteur);
        $reservee     = $this->production($producteur);
        $this->reservation($reservee, $gestionnaire, 'en_attente');

        $this->en(Utilisateur::factory()->create())->putJson("/api/productions/{$libre->id}/annuler")->assertStatus(403);
        $this->en($producteur)->putJson("/api/productions/{$reservee->id}/annuler")->assertStatus(422);
        $this->en($producteur)->putJson("/api/productions/{$libre->id}/annuler")->assertOk()->assertJsonPath('production.statut', 'annulee');
        $this->en($producteur)->putJson("/api/productions/{$libre->id}/annuler")->assertStatus(422);
        $this->en($producteur)->putJson("/api/productions/{$libre->id}", ['quantite_estimee' => 5])->assertStatus(422);

        // Une récolte annulée ne peut plus être réservée ; elle n'est pas supprimée
        $this->en($producteur)->postJson('/api/reservations', [
            'entrepot_id' => $gestionnaire->entrepot->id, 'production_id' => $libre->id,
            'produit' => 'riz', 'quantite_reservee' => 10, 'date_debut' => '2026-06-02',
        ])->assertStatus(422)->assertJsonValidationErrors('production_id');
        $this->assertDatabaseHas('productions', ['id' => $libre->id, 'statut' => 'annulee']);
    }

    public function test_le_gestionnaire_liste_les_commandes_de_son_entrepot(): void
    {
        $p = $this->stockEtCommande();
        $this->stockEtCommande(); // commande d'un autre entrepôt : invisible

        $this->en($p->gestionnaire)->getJson('/api/commandes?statut=en_attente')->assertOk()
             ->assertJsonCount(1, 'commandes')
             ->assertJsonPath('commandes.0.id', $p->commande->id)
             ->assertJsonPath('commandes.0.acheteur.utilisateur.id', $p->acheteur->id);
        $this->en($p->gestionnaire)->getJson('/api/commandes?statut=confirmee')->assertOk()->assertJsonCount(0, 'commandes');
        $this->en($p->acheteur)->getJson('/api/commandes')->assertStatus(403);
    }
}

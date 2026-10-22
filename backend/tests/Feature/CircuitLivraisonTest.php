<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Livraison;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitLivraison;
use Tests\TestCase;

/**
 * Lot 3 — circuit « entrepôt → acheteur » (cahier des charges v2, §5) :
 * préparation, livraison organisée avec transporteur, mission acceptée,
 * chargement (sortie du stock), départ, arrivée, remise par code ou par
 * l'acheteur ; panier ; annulation jusqu'au chargement.
 */
class CircuitLivraisonTest extends TestCase
{
    use ConstruitLivraison, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparerLivraison();
    }

    public function test_circuit_complet_jusqua_la_remise_par_code(): void
    {
        $c = $this->commandeJusqua('confirmee');
        $this->assertSame(['preparer', 'annuler_commande'], $this->vueEntrepot($c->id)['actions']);
        $this->assertSame(['demander_annulation'], $this->vueAcheteur($c->id)['actions']);

        // Les étapes se suivent : pas de livraison avant que la commande soit prête
        $this->en($this->gestionnaire)->putJson("/api/commandes/{$c->id}/prete")->assertStatus(422);
        $this->en($this->gestionnaire)->postJson('/api/livraisons', ['commande_id' => $c->id, 'transporteur_id' => $this->transporteurA->transporteur->id])
             ->assertStatus(422)->assertJsonPath('statut_actuel', 'confirmee');
        $this->en($this->gestionnaire)->putJson("/api/commandes/{$c->id}/preparer")->assertOk();
        $this->en($this->gestionnaire)->putJson("/api/commandes/{$c->id}/prete")->assertOk();
        $this->assertContains('commande_prete', $this->typesNotifications($this->acheteur));
        $this->assertSame(['organiser_livraison', 'annuler_commande'], $this->vueEntrepot($c->id)['actions']);

        // Transporteur obligatoire ; départ et destination repris de l'entrepôt et du profil acheteur
        $this->en($this->gestionnaire)->postJson('/api/livraisons', ['commande_id' => $c->id])->assertStatus(422)->assertJsonValidationErrors('transporteur_id');
        $this->en($this->gestionnaire)->postJson('/api/livraisons', ['commande_id' => $c->id, 'transporteur_id' => $this->transporteurA->transporteur->id])
             ->assertStatus(201)->assertJsonPath('livraison.statut', 'en_attente');
        $this->assertNotEmpty(Livraison::sole()->origine);
        $this->assertSame('Mission à accepter par le transporteur', $this->vueAcheteur($c->id)['prochaine_etape']['libelle']);

        // A refuse (motif obligatoire) : la mission revient à l'entrepôt, qui la confie à B
        $lid = $this->livraisonId($c->id);
        $this->en($this->transporteurA)->putJson("/api/livraisons/{$lid}/refuser-mission")->assertStatus(422);
        $this->en($this->transporteurA)->putJson("/api/livraisons/{$lid}/refuser-mission", ['motif' => 'Camion indisponible'])->assertOk();
        $this->assertContains('mission_refusee', $this->typesNotifications($this->gestionnaire));
        $this->assertSame('Transporteur à affecter', $this->vueEntrepot($c->id)['prochaine_etape']['libelle']);
        $this->assertNull($this->vueTransporteur($this->transporteurA, $c->id));
        $this->en($this->gestionnaire)->putJson("/api/livraisons/{$lid}/transporteur", ['transporteur_id' => $this->transporteurB->transporteur->id])->assertOk();

        $this->assertSame(['accepter_mission', 'refuser_mission'], $this->vueTransporteur($this->transporteurB, $c->id)['actions']);
        $this->etapeLivraison($this->transporteurB, $c->id, 'chargee')->assertStatus(422);
        $this->en($this->transporteurB)->putJson("/api/livraisons/{$lid}/accepter-mission")->assertOk()->assertJsonPath('livraison.statut', 'prise_en_charge');
        $this->assertContains('mission_acceptee', $this->typesNotifications($this->gestionnaire));
        $vue = $this->vueTransporteur($this->transporteurB, $c->id);
        $this->assertSame(['charger', 'declarer_incident'], $vue['actions']);
        $this->assertNotNull($vue['commande']['acheteur']['utilisateur']['telephone']);

        // Chargement : la quantité réservée sort du stock, l'acheteur reçoit son code
        $this->etapeLivraison($this->transporteurB, $c->id, 'en_cours')->assertStatus(422);
        $this->assertSame([80.0, 10.0], $this->niveaux());
        $this->etapeLivraison($this->transporteurB, $c->id, 'chargee')->assertOk();
        $this->assertSame([70.0, 0.0], $this->niveaux());
        $code = $this->vueAcheteur($c->id)['code_reception'];
        $this->assertMatchesRegularExpression('/^\d{4}$/', $code);
        $this->assertTrue(collect($this->messagesNotifications($this->acheteur))->contains(fn ($m) => str_contains($m, $code)));
        // Le code n'est donné qu'à l'acheteur
        $this->assertNull($this->vueEntrepot($c->id)['code_reception']);
        $this->assertArrayNotHasKey('code_reception', $this->vueTransporteur($this->transporteurB, $c->id)['commande']);
        $this->assertSame([], $this->vueEntrepot($c->id)['actions']);

        $this->etapeLivraison($this->transporteurB, $c->id, 'en_cours')->assertOk();
        $this->assertContains('livraison_en_cours', $this->typesNotifications($this->acheteur));
        $this->en($this->transporteurB)->putJson("/api/livraisons/{$lid}/remise", ['code' => $code])->assertStatus(422);
        $this->etapeLivraison($this->transporteurB, $c->id, 'arrivee')->assertOk();
        $this->assertContains('livraison_arrivee', $this->typesNotifications($this->acheteur));
        $this->assertSame(['confirmer_reception', 'declarer_anomalie'], $this->vueAcheteur($c->id)['actions']);
        $this->assertSame(['confirmer_par_code', 'declarer_incident'], $this->vueTransporteur($this->transporteurB, $c->id)['actions']);

        // Mauvais code refusé, bon code accepté
        $faux = $code === '0000' ? '1111' : '0000';
        $this->en($this->transporteurB)->putJson("/api/livraisons/{$lid}/remise", ['code' => $faux])->assertStatus(422);
        $this->en($this->transporteurB)->putJson("/api/livraisons/{$lid}/remise", ['code' => $code])->assertOk()->assertJsonPath('livraison.statut', 'livree');

        $this->assertSame('receptionnee', $c->fresh()->statut);
        $this->assertNull($this->vueAcheteur($c->id)['code_reception']);
        $this->assertContains('livraison_terminee', $this->typesNotifications($this->acheteur));
        $this->assertContains('livraison_terminee', $this->typesNotifications($this->producteur));
        $this->assertSame([70.0, 0.0], $this->niveaux());
        $this->assertSame(
            ['creation', 'refus_mission', 'assignation_transporteur', 'acceptation_mission', 'chargee', 'en_cours', 'arrivee', 'code_errone', 'livraison'],
            Livraison::sole()->historique()->pluck('action')->all(),
        );
    }

    public function test_lacheteur_confirme_lui_meme_la_reception(): void
    {
        $c = $this->commandeJusqua('en_cours');
        $this->en($this->acheteur)->putJson("/api/commandes/{$c->id}/reception")->assertStatus(422);
        $this->etapeLivraison($this->transporteurA, $c->id, 'arrivee')->assertOk();

        $this->en($this->transporteurB)->putJson("/api/commandes/{$c->id}/reception")->assertStatus(403);
        $this->en($this->acheteur)->putJson("/api/commandes/{$c->id}/reception")->assertOk();
        $this->en($this->acheteur)->putJson("/api/commandes/{$c->id}/reception")->assertStatus(422);

        $this->assertSame(['receptionnee', 'livree'], [$c->fresh()->statut, $c->fresh()->livraison->statut]);
        $this->assertContains('reception_confirmee', $this->typesNotifications($this->transporteurA));
        $this->assertSame([], $this->vueTransporteur($this->transporteurA, $c->id)['actions']);
    }

    public function test_panier_tout_ou_rien(): void
    {
        $mil = $this->lot(30);
        $mil->update(['produit' => 'mil']);

        $this->en($this->acheteur)->postJson('/api/commandes', ['lignes' => [
            ['stock_id' => $this->stock->id, 'quantite' => 20], ['stock_id' => $mil->id, 'quantite' => 50],
        ]])->assertStatus(422)->assertJsonPath('ligne', 1)->assertJsonPath('quantite_disponible', 30);
        $this->assertSame(0, Commande::count());

        $this->en($this->acheteur)->postJson('/api/commandes', ['lignes' => [
            ['stock_id' => $this->stock->id, 'quantite' => 20], ['stock_id' => $this->stock->id, 'quantite' => 5],
        ]])->assertStatus(422)->assertJsonValidationErrors('lignes.0.stock_id');

        $reponse = $this->en($this->acheteur)->postJson('/api/commandes', ['lignes' => [
            ['stock_id' => $this->stock->id, 'quantite' => 20], ['stock_id' => $mil->id, 'quantite' => 30],
        ]])->assertStatus(201);

        $this->assertCount(2, $reponse->json('commandes'));
        $this->assertSame([$reponse->json('panier')], Commande::pluck('panier')->unique()->values()->all());
        $this->assertMatchesRegularExpression('/^PAN-/', $reponse->json('panier'));
        $this->assertSame([3000.0, 4500.0], Commande::orderBy('id')->pluck('prix')->map(fn ($p) => (float) $p)->all());
    }

    public function test_annulation_par_lentrepot_jusquau_chargement(): void
    {
        $c = $this->commandeJusqua('acceptee');
        $this->assertSame([80.0, 10.0], $this->niveaux());

        $this->en($this->gestionnaire)->putJson("/api/commandes/{$c->id}/annuler-confirmation")->assertStatus(422);
        $this->en($this->gestionnaire)->putJson("/api/commandes/{$c->id}/annuler-confirmation", ['motif' => 'Rupture qualité'])->assertOk();

        $this->assertSame([80.0, 0.0], $this->niveaux());
        $this->assertSame(['annulee', 'annulee'], [$c->fresh()->statut, Livraison::sole()->statut]);
        $this->assertContains('mission_annulee', $this->typesNotifications($this->transporteurA));
        $this->assertContains('commande_annulation_confirmation', $this->typesNotifications($this->acheteur));
        $this->assertSame([], $this->vueTransporteur($this->transporteurA, $c->id)['actions']);
    }

    public function test_transporteur_non_modifiable_apres_chargement(): void
    {
        $c   = $this->commandeJusqua('chargee');
        $lid = $this->livraisonId($c->id);

        $this->en($this->gestionnaire)->putJson("/api/livraisons/{$lid}/transporteur", ['transporteur_id' => $this->transporteurB->transporteur->id])
             ->assertStatus(422);
        $this->en($this->transporteurB)->putJson("/api/livraisons/{$lid}/etape", ['etape' => 'en_cours'])->assertStatus(403);
        $this->en($this->acheteur)->postJson("/api/commandes/{$c->id}/demande-annulation")->assertStatus(422);
    }

    public function test_changement_de_transporteur_avant_chargement_remet_la_mission_a_accepter(): void
    {
        $c   = $this->commandeJusqua('acceptee');
        $lid = $this->livraisonId($c->id);

        $this->en($this->gestionnaire)->putJson("/api/livraisons/{$lid}/transporteur", ['transporteur_id' => $this->transporteurB->transporteur->id])->assertOk();

        $this->assertSame(['en_attente', null], [Livraison::sole()->statut, Livraison::sole()->mission_acceptee_le]);
        $this->assertContains('mission_retiree', $this->typesNotifications($this->transporteurA));
        $this->etapeLivraison($this->transporteurA, $c->id, 'chargee')->assertStatus(403);
    }

    public function test_anciennes_routes_du_transporteur(): void
    {
        $c   = $this->commandeJusqua('organisee');
        $lid = $this->livraisonId($c->id);

        // « en_cours » franchit acceptation et chargement ; « livree » exige le code
        $this->en($this->transporteurA)->putJson("/api/livraisons/{$lid}/statut", ['statut' => 'en_cours'])->assertOk()->assertJsonPath('livraison.statut', 'en_cours');
        $this->assertSame([70.0, 0.0], $this->niveaux());
        $this->assertSame('en_livraison', $c->fresh()->statut);
        $this->en($this->transporteurA)->putJson("/api/livraisons/{$lid}/statut", ['statut' => 'livree'])->assertStatus(422);
        $this->en($this->transporteurA)->putJson("/api/livraisons/{$lid}/statut", ['statut' => 'en_cours'])->assertStatus(422);
    }

    public function test_charge_des_transporteurs_compte_les_livraisons_en_cours(): void
    {
        $this->commandeJusqua('chargee');

        $charges = collect($this->en($this->gestionnaire)->getJson('/api/transporteurs')->json('transporteurs'))->pluck('missions_actives', 'id');
        $this->assertSame(1, $charges[$this->transporteurA->transporteur->id]);
        $this->assertSame(0, $charges[$this->transporteurB->transporteur->id]);
    }
}

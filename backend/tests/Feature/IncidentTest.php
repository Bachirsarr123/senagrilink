<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Livraison;
use App\Models\Reservation;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitLivraison;
use Tests\TestCase;

/**
 * Lot 3 — incidents (cahier des charges v2, §5.2) : déclaration par le bon
 * acteur, blocage du transport, décision par l'entrepôt concerné, effets sur
 * le stock, notifications ; demandes d'annulation et anomalies de l'acheteur.
 */
class IncidentTest extends TestCase
{
    use ConstruitLivraison, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparerLivraison();
    }

    private function declarer(Utilisateur $u, string $sujet, int $id, string $type, string $description = 'Pneu crevé')
    {
        return $this->en($u)->postJson('/api/incidents', ['sujet_type' => $sujet, 'sujet_id' => $id, 'type' => $type, 'description' => $description]);
    }

    private function decider(int $incidentId, string $decision, array $extra = [], ?Utilisateur $u = null)
    {
        return $this->en($u ?? $this->gestionnaire)->putJson("/api/incidents/{$incidentId}/resoudre", ['decision' => $decision, ...$extra]);
    }

    public function test_panne_apres_chargement_bloque_puis_un_autre_transporteur_reprend(): void
    {
        $c   = $this->commandeJusqua('chargee');
        $lid = $this->livraisonId($c->id);

        $this->declarer($this->transporteurB, 'livraison', $lid, 'panne')->assertStatus(403);
        $this->declarer($this->acheteur, 'livraison', $lid, 'panne')->assertStatus(403);
        $this->declarer($this->transporteurA, 'livraison', $lid, 'inconnu')->assertStatus(422);
        $iid = $this->declarer($this->transporteurA, 'livraison', $lid, 'panne')->assertStatus(201)->json('incident.id');
        $this->declarer($this->transporteurA, 'livraison', $lid, 'panne')->assertStatus(422);

        $this->assertContains('incident', $this->typesNotifications($this->gestionnaire));
        $this->assertContains('incident', $this->typesNotifications($this->acheteur));
        $this->assertTrue($this->vueAcheteur($c->id)['alerte_livraison']);
        // Transport arrêté jusqu'à la décision
        $this->assertSame(['declarer_incident'], $this->vueTransporteur($this->transporteurA, $c->id)['actions']);
        $this->etapeLivraison($this->transporteurA, $c->id, 'en_cours')->assertStatus(422);

        $incident = collect($this->en($this->gestionnaire)->getJson('/api/incidents')->json('incidents'))->firstWhere('id', $iid);
        $this->assertSame(['reprendre', 'changer_transporteur', 'retour_entrepot', 'annuler_commande'], $incident['decisions']);
        $this->assertSame($c->numero_commande, $incident['sujet']['commande']);
        $this->assertSame([], collect($this->en($this->acheteur)->getJson('/api/incidents')->json('incidents'))->pluck('id')->all());

        $this->decider($iid, 'changer_transporteur', ['transporteur_id' => $this->transporteurB->transporteur->id], Utilisateur::factory()->gestionnaire()->create())->assertStatus(403);
        $this->decider($iid, 'changer_transporteur')->assertStatus(422)->assertJsonValidationErrors('transporteur_id');
        $this->decider($iid, 'changer_transporteur', ['transporteur_id' => $this->transporteurB->transporteur->id])->assertOk();
        $this->decider($iid, 'reprendre')->assertStatus(422);

        // Marchandise transférée : B repart directement, A n'a plus la mission
        $l = Livraison::sole();
        $this->assertSame([$this->transporteurB->transporteur->id, 'chargee'], [$l->transporteur_id, $l->statut]);
        $this->assertSame(['resolu', 'changer_transporteur'], [Incident::find($iid)->statut, Incident::find($iid)->decision]);
        $this->assertContains('incident', $this->typesNotifications($this->transporteurB));
        $this->assertNull($this->vueTransporteur($this->transporteurA, $c->id));
        $this->assertSame(['partir', 'declarer_incident'], $this->vueTransporteur($this->transporteurB, $c->id)['actions']);
        $this->etapeLivraison($this->transporteurB, $c->id, 'en_cours')->assertOk();
        $this->assertSame([70.0, 0.0], $this->niveaux());
    }

    public function test_retard_ne_bloque_pas_et_la_livraison_reprend(): void
    {
        $c   = $this->commandeJusqua('en_cours');
        $iid = $this->declarer($this->transporteurA, 'livraison', $this->livraisonId($c->id), 'retard', 'Bouchons')->assertStatus(201)->json('incident.id');

        $this->assertSame(['arriver', 'declarer_incident'], $this->vueTransporteur($this->transporteurA, $c->id)['actions']);
        $this->decider($iid, 'reprendre')->assertOk();
        $this->assertFalse($this->vueAcheteur($c->id)['alerte_livraison']);
    }

    public function test_acheteur_absent_retour_a_lentrepot_puis_nouvelle_livraison(): void
    {
        $c   = $this->commandeJusqua('arrivee');
        $iid = $this->declarer($this->transporteurA, 'livraison', $this->livraisonId($c->id), 'acheteur_absent', 'Personne sur place')->json('incident.id');
        $this->assertSame(['declarer_incident'], $this->vueTransporteur($this->transporteurA, $c->id)['actions']);

        $this->decider($iid, 'retour_entrepot', ['motif' => 'Acheteur injoignable'])->assertOk();

        // La marchandise rentre en stock, de nouveau réservée pour la commande, qui redevient prête
        $this->assertSame([80.0, 10.0], $this->niveaux());
        $this->assertSame(['prete', 'reserve', null], [$c->fresh()->statut, $c->fresh()->etat_stock, $c->fresh()->code_reception]);
        $this->assertSame('retournee', Livraison::sole()->statut);
        $this->assertSame(['organiser_livraison', 'annuler_commande'], $this->vueEntrepot($c->id)['actions']);
        $this->en($this->gestionnaire)->postJson('/api/livraisons', ['commande_id' => $c->id, 'transporteur_id' => $this->transporteurB->transporteur->id])->assertStatus(201);
        $this->assertSame('Mission à accepter par le transporteur', $this->vueAcheteur($c->id)['prochaine_etape']['libelle']);
    }

    public function test_annulation_apres_chargement_rend_la_marchandise_au_stock(): void
    {
        $c   = $this->commandeJusqua('en_cours');
        $iid = $this->declarer($this->transporteurA, 'livraison', $this->livraisonId($c->id), 'marchandise_abimee')->json('incident.id');

        $this->decider($iid, 'annuler_commande')->assertStatus(422);
        $this->decider($iid, 'annuler_commande', ['motif' => 'Sacs mouillés'])->assertOk();

        $this->assertSame([80.0, 0.0], $this->niveaux());
        $this->assertSame(['annulee', 'retourne', 'retournee'], [$c->fresh()->statut, $c->fresh()->etat_stock, Livraison::sole()->statut]);
        $this->assertContains('retour', $this->stock->mouvements()->pluck('type')->all());
    }

    public function test_demande_dannulation_de_lacheteur(): void
    {
        $c = $this->commandeJusqua('prete');

        $this->en(Utilisateur::factory()->acheteur()->create())->postJson("/api/commandes/{$c->id}/demande-annulation")->assertStatus(403);
        $iid = $this->en($this->acheteur)->postJson("/api/commandes/{$c->id}/demande-annulation", ['motif' => 'Plus besoin'])->assertStatus(201)->json('incident.id');
        $this->en($this->acheteur)->postJson("/api/commandes/{$c->id}/demande-annulation")->assertStatus(422);
        $this->assertNotContains('demander_annulation', $this->vueAcheteur($c->id)['actions']);
        $this->assertContains('incident', $this->typesNotifications($this->gestionnaire));

        $this->assertSame(['accepter_annulation', 'refuser_annulation'],
            collect($this->en($this->gestionnaire)->getJson('/api/incidents')->json('incidents'))->firstWhere('id', $iid)['decisions']);
        $this->decider($iid, 'refuser_annulation')->assertStatus(422);
        $this->decider($iid, 'accepter_annulation')->assertOk();

        $this->assertSame([80.0, 0.0], $this->niveaux());
        $this->assertSame('annulee', $c->fresh()->statut);
        $this->assertContains('incident', $this->typesNotifications($this->acheteur));
    }

    public function test_demande_dannulation_refusee_puis_impossible_apres_chargement(): void
    {
        $c   = $this->commandeJusqua('acceptee');
        $iid = $this->en($this->acheteur)->postJson("/api/commandes/{$c->id}/demande-annulation")->json('incident.id');

        // Chargée entre-temps : l'annulation n'est plus acceptable
        $this->etapeLivraison($this->transporteurA, $c->id, 'chargee')->assertOk();
        $this->decider($iid, 'accepter_annulation')->assertStatus(422);
        $this->decider($iid, 'refuser_annulation', ['motif' => 'Déjà en route'])->assertOk();
        $this->assertSame('en_livraison', $c->fresh()->statut);
        $this->en($this->acheteur)->postJson("/api/commandes/{$c->id}/demande-annulation")->assertStatus(422);
    }

    public function test_anomalie_a_la_reception_puis_litige_clos(): void
    {
        $c = $this->commandeJusqua('en_cours');
        $this->en($this->acheteur)->postJson("/api/commandes/{$c->id}/anomalie", ['type' => 'produit_abime', 'description' => 'x'])->assertStatus(422);
        $this->etapeLivraison($this->transporteurA, $c->id, 'arrivee')->assertOk();

        $this->en($this->acheteur)->postJson("/api/commandes/{$c->id}/anomalie", ['type' => 'panne', 'description' => 'x'])->assertStatus(422);
        $iid = $this->en($this->acheteur)->postJson("/api/commandes/{$c->id}/anomalie", ['type' => 'quantite_manquante', 'description' => 'Il manque 2 sacs'])
                    ->assertStatus(201)->json('incident.id');

        $this->assertSame(['litige', 'livree'], [$c->fresh()->statut, Livraison::sole()->statut]);
        $this->assertSame('Anomalie à traiter par l\'entrepôt', $this->vueAcheteur($c->id)['prochaine_etape']['libelle']);
        $this->decider($iid, 'cloturer_litige', ['motif' => 'Avoir de 2 sacs accordé'])->assertOk();
        $this->assertSame('receptionnee', $c->fresh()->statut);
    }

    public function test_anomalie_marchandise_reprise(): void
    {
        $c   = $this->commandeJusqua('arrivee');
        $iid = $this->en($this->acheteur)->postJson("/api/commandes/{$c->id}/anomalie", ['type' => 'livraison_incorrecte', 'description' => 'Ce n\'est pas du riz'])->json('incident.id');

        $this->decider($iid, 'reprendre_marchandise', ['motif' => 'Erreur de lot'])->assertOk();
        $this->assertSame([80.0, 0.0], $this->niveaux());
        $this->assertSame('annulee', $c->fresh()->statut);
    }

    public function test_incident_sur_un_acheminement_vers_lentrepot(): void
    {
        $rid = Reservation::create([
            'entrepot_id' => $this->gestionnaire->entrepot->id, 'producteur_id' => $this->producteur->producteur->id,
            'production_id' => $this->production($this->producteur)->id, 'transporteur_id' => $this->transporteurA->transporteur->id,
            'produit' => 'riz', 'quantite_reservee' => 40, 'date_debut' => '2026-06-02', 'date_reservation' => now(),
            'statut' => 'en_route', 'mode_transport' => 'transporteur', 'mission_acceptee_le' => now(),
        ])->id;
        $vue = fn (Utilisateur $u) => collect($this->en($u)->getJson('/api/reservations')->json('reservations'))->firstWhere('id', $rid);

        $this->assertSame(['arriver', 'declarer_incident'], $vue($this->transporteurA)['actions']);
        $this->declarer($this->producteur, 'reservation', $rid, 'panne')->assertStatus(403);
        $iid = $this->declarer($this->transporteurA, 'reservation', $rid, 'panne')->assertStatus(201)->json('incident.id');

        $this->assertSame(['declarer_incident'], $vue($this->transporteurA)['actions']);
        $this->en($this->transporteurA)->putJson("/api/reservations/{$rid}/etape", ['etape' => 'arrivee_entrepot'])->assertStatus(422);

        $this->decider($iid, 'changer_transporteur', ['transporteur_id' => $this->transporteurB->transporteur->id])->assertOk();
        $this->assertSame(['en_route', $this->transporteurB->transporteur->id], [Reservation::find($rid)->statut, Reservation::find($rid)->transporteur_id]);
        $this->en($this->transporteurB)->putJson("/api/reservations/{$rid}/etape", ['etape' => 'arrivee_entrepot'])->assertOk();
    }

    public function test_ancien_signalement_de_probleme_devient_un_incident(): void
    {
        $c = $this->commandeJusqua('en_cours');

        $this->en($this->transporteurA)->postJson("/api/livraisons/{$this->livraisonId($c->id)}/probleme", ['motif' => 'Route coupée'])->assertStatus(201);
        $this->assertSame(['autre', 'Route coupée', 'ouvert'], [Incident::sole()->type, Incident::sole()->description, Incident::sole()->statut]);
        $this->assertTrue($this->vueAcheteur($c->id)['alerte_livraison']);
    }
}

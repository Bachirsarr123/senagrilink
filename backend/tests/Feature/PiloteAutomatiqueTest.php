<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\HistoriqueOperation;
use App\Models\Livraison;
use App\Models\PositionGps;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitLivraison;
use Tests\TestCase;

/**
 * Lot 11 : pilote automatique de l'entrepôt. Le système accepte, confirme et
 * confie au transporteur libre le plus proche, puis au suivant ; le gestionnaire
 * ne garde que la préparation, la réception et les exceptions.
 */
class PiloteAutomatiqueTest extends TestCase
{
    use ConstruitLivraison, RefreshDatabase;

    protected bool $piloteAutomatique = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparerLivraison();
        $this->gestionnaire->entrepot->update(['latitude' => 14.79, 'longitude' => -16.93, 'capacite' => 1000]);
        // B a été vu près de l'entrepôt : il est proposé en premier ; A ensuite
        PositionGps::create(['utilisateur_id' => $this->transporteurB->id, 'transporteur_id' => $this->transporteurB->transporteur->id,
            'latitude' => 14.80, 'longitude' => -16.94, 'timestamp' => now()->subHour()]);
    }

    private function deposer(string $mode = 'transporteur')
    {
        return $this->en($this->producteur)->postJson('/api/reservations', [
            'entrepot_id' => $this->gestionnaire->entrepot->id, 'production_id' => $this->production($this->producteur)->id,
            'mode_transport' => $mode, 'quantite_reservee' => 10, 'date_debut' => now()->toDateString(),
            'collecte_latitude' => 14.81, 'collecte_longitude' => -16.95,
        ]);
    }

    public function test_depot_accepte_et_confie_sans_le_gestionnaire_puis_relance_au_suivant(): void
    {
        $reponse = $this->deposer()->assertStatus(201);
        $this->assertStringContainsString('Un transporteur va venir', $reponse->json('message'));
        $r = Reservation::find($reponse->json('reservation.id'));
        $this->assertSame(['confirmee', $this->transporteurB->transporteur->id], [$r->statut, $r->transporteur_id]);

        // Historique : mêmes étapes qu'à la main, marquées « automatique », au nom du gestionnaire
        $h = HistoriqueOperation::where('sujet_type', 'reservation')->where('sujet_id', $r->id)->whereIn('action', ['confirmation', 'assignation_transporteur'])->get();
        $this->assertCount(2, $h);
        $this->assertTrue($h->every(fn ($e) => ($e->details['automatique'] ?? false) === true && $e->utilisateur_id === $this->gestionnaire->id));

        // B refuse : proposée tout de suite à A
        $this->en($this->transporteurB)->putJson("/api/reservations/{$r->id}/refuser-mission", ['motif' => 'Panne'])->assertOk();
        $this->assertSame($this->transporteurA->transporteur->id, $r->fresh()->transporteur_id);

        // A ne répond pas dans le délai : plus personne de libre, le gestionnaire est prévenu
        $this->travelTo(now()->addMinutes(11));
        app(\App\Services\PiloteAutomatique::class)->relancerSansReponse();
        $this->assertSame($this->transporteurA->transporteur->id, $r->fresh()->transporteur_id);
        $this->assertTrue($this->gestionnaire->fresh()->notifications->contains(fn ($n) => str_contains($n->data['message'] ?? '', 'Aucun transporteur libre')));
    }

    public function test_sans_reponse_la_mission_passe_au_suivant(): void
    {
        $r = Reservation::find($this->deposer()->json('reservation.id'));
        $this->assertSame($this->transporteurB->transporteur->id, $r->transporteur_id);

        $this->travelTo(now()->addMinutes(5));
        $this->assertSame(0, app(\App\Services\PiloteAutomatique::class)->relancerSansReponse()); // encore dans le délai
        $this->travelTo(now()->addMinutes(6));
        $this->assertSame(1, app(\App\Services\PiloteAutomatique::class)->relancerSansReponse());
        $this->assertSame($this->transporteurA->transporteur->id, $r->fresh()->transporteur_id);
        // L'ancien est prévenu que la mission lui est retirée
        $this->assertTrue($this->transporteurB->fresh()->notifications->contains(fn ($n) => str_contains($n->data['message'] ?? '', 'retirée')));
    }

    public function test_commande_confirmee_d_office_puis_livraison_confiee_quand_elle_est_prete(): void
    {
        // Lot 13 : commandée et payée avec Wave d'un seul geste ; rien ne bouge avant le paiement
        $r = $this->en($this->acheteur)->postJson('/api/commandes', ['stock_id' => $this->stock->id, 'quantite' => 5, 'moyen' => 'wave'])
            ->assertStatus(201)->assertJsonPath('commande.statut', 'en_attente');
        $id = $r->json('commande.id');
        $this->assertNull(collect($this->en($this->gestionnaire)->getJson('/api/a-faire')->json('elements'))->firstWhere('sujet_id', $id));
        $this->assertNull(collect($this->en($this->gestionnaire)->getJson('/api/journee')->json('a_preparer'))->firstWhere('id', $id));
        $this->en($this->acheteur)->postJson("/api/paiements/{$r->json('paiement.reference')}/simulation", ['resultat' => 'reussi'])->assertOk();
        $this->assertSame('confirmee', Commande::find($id)->statut);
        $this->assertSame(5.0, (float) $this->stock->fresh()->quantite_reservee); // gardée une seule fois

        // « À faire » du gestionnaire : seulement « Commande prête »
        $e = collect($this->en($this->gestionnaire)->getJson('/api/a-faire')->json('elements'))->firstWhere('sujet_id', $id);
        $this->assertSame('prete', $e['action']);

        $this->en($this->gestionnaire)->putJson("/api/commandes/{$id}/preparer")->assertOk();
        $this->en($this->gestionnaire)->putJson("/api/commandes/{$id}/prete")->assertOk()
             ->assertJsonPath('message', 'Commande prête. Un transporteur a été prévenu automatiquement.');
        $l = Commande::find($id)->livraison;
        $this->assertSame([$this->transporteurB->transporteur->id, 'en_attente'], [$l->transporteur_id, $l->statut]);

        // Refus de B : la livraison passe à A
        $this->en($this->transporteurB)->putJson("/api/livraisons/{$l->id}/refuser-mission", ['motif' => 'Trop loin'])->assertOk();
        $this->assertSame($this->transporteurA->transporteur->id, Livraison::find($l->id)->transporteur_id);
    }

    public function test_chaque_regle_se_coupe_dans_les_reglages(): void
    {
        $this->en($this->gestionnaire)->putJson('/api/profile', ['auto_accepter_depots' => false, 'auto_confirmer_commandes' => false, 'delai_reponse_min' => 20])->assertOk();
        $e = $this->gestionnaire->entrepot->fresh();
        $this->assertSame([false, false, true, 20], [$e->auto_accepter_depots, $e->auto_confirmer_commandes, $e->auto_affecter_transporteurs, $e->delai_reponse_min]);

        $this->assertSame('en_attente', Reservation::find($this->deposer()->json('reservation.id'))->statut);
        $this->en($this->acheteur)->postJson('/api/commandes', ['stock_id' => $this->stock->id, 'quantite' => 5, 'moyen' => 'especes'])->assertJsonPath('commande.statut', 'en_attente');

        // Le producteur qui livre lui-même : accepté, mais aucun transporteur
        $this->en($this->gestionnaire)->putJson('/api/profile', ['auto_accepter_depots' => true])->assertOk();
        $r = Reservation::find($this->deposer('producteur')->json('reservation.id'));
        $this->assertSame(['confirmee', null], [$r->statut, $r->transporteur_id]);
    }

    public function test_depot_trop_gros_reste_au_gestionnaire(): void
    {
        $this->gestionnaire->entrepot->update(['capacite' => 0]);
        $this->deposer()->assertStatus(422); // la règle de capacité s'applique avant tout
        $this->gestionnaire->entrepot->update(['capacite' => 1000]);
        $this->assertSame(0, Reservation::where('statut', 'confirmee')->whereNull('transporteur_id')->count());
    }

    public function test_ma_journee_et_fiche_agriculteur(): void
    {
        $this->producteur->update(['prenom' => 'Awa', 'nom' => 'Diallo', 'telephone' => '771112233']);
        $r = Reservation::find($this->deposer()->json('reservation.id'));
        $cid = $this->en($this->acheteur)->postJson('/api/commandes', ['stock_id' => $this->stock->id, 'quantite' => 5, 'moyen' => 'especes'])->json('commande.id');

        $j = $this->en($this->gestionnaire)->getJson('/api/journee')->assertOk()->json();
        $arrivee = collect($j['arrivent'])->firstWhere('id', $r->id);
        $this->assertSame(['Awa Diallo', 'confirmee'], [$arrivee['producteur']['nom'], $arrivee['statut']]);
        $this->assertNotNull($arrivee['transporteur']);
        $this->assertSame('prete', collect($j['a_preparer'])->firstWhere('id', $cid)['action']);
        $this->assertTrue($j['pilote']['auto_affecter_transporteurs']);
        $this->en($this->acheteur)->getJson('/api/journee')->assertStatus(403);

        // Recherche d'un agriculteur, puis sa fiche
        foreach (['awa', 'DIALLO', '7711'] as $recherche) {
            $this->assertSame([$this->producteur->producteur->id], array_column($this->en($this->gestionnaire)->getJson('/api/entrepot/producteurs?q='.$recherche)->json('producteurs'), 'id'));
        }
        $this->assertSame([], $this->en($this->gestionnaire)->getJson('/api/entrepot/producteurs?q=personne')->json('producteurs'));
        $fiche = $this->en($this->gestionnaire)->getJson('/api/entrepot/producteurs/'.$this->producteur->producteur->id)->assertOk()->json();
        $this->assertSame('Awa Diallo', $fiche['producteur']['nom']);
        $this->assertNotEmpty($fiche['depots']);
        $this->assertNotEmpty($fiche['stocks']);
        $this->assertSame($cid, $fiche['ventes'][0]['id']);
        // Un autre entrepôt ne voit pas cet agriculteur
        $autre = \App\Models\Utilisateur::factory()->gestionnaire()->create();
        $this->en($autre)->getJson('/api/entrepot/producteurs/'.$this->producteur->producteur->id)->assertStatus(404);
    }
}

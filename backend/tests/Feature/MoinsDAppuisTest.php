<?php

namespace Tests\Feature;

use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitLivraison;
use Tests\TestCase;

/**
 * Lot 10 : encore moins d'appuis. Le transporteur : accepter, (arrivée détectée
 * par le GPS), démarrer, je suis arrivé. Le gestionnaire : un appui par dépôt,
 * deux par commande. Les étapes restent toutes datées dans l'historique.
 */
class MoinsDAppuisTest extends TestCase
{
    use ConstruitLivraison, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparerLivraison();
        $this->gestionnaire->entrepot->update(['latitude' => 14.79, 'longitude' => -16.93]);
    }

    private function aFaire($u, int $id)
    {
        return collect($this->en($u)->getJson('/api/a-faire')->json('elements'))->firstWhere('sujet_id', $id);
    }

    private function guider($u, string $type, int $id, float $lat, float $lng)
    {
        return $this->en($u)->getJson("/api/navigation/{$type}/{$id}?latitude={$lat}&longitude={$lng}")->assertOk()->json();
    }

    public function test_depot_accepter_et_confier_puis_trois_appuis_du_transporteur(): void
    {
        $r = $this->reservation($this->production($this->producteur), $this->gestionnaire, 'en_attente');
        $r->update(['mode_transport' => 'transporteur', 'collecte_latitude' => 14.85, 'collecte_longitude' => -16.80]);

        // Gestionnaire : un seul appui, « Accepter et confier à … » (le client enchaîne les deux étapes)
        $e = $this->aFaire($this->gestionnaire, $r->id);
        $this->assertSame('accepter_et_confier', $e['action']);
        $tid = $e['transporteur_propose']['id'];
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$r->id}/confirmer")->assertOk();
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$r->id}/assigner-transporteur", ['transporteur_id' => $tid])->assertOk();

        $t = $tid === $this->transporteurA->transporteur->id ? $this->transporteurA : $this->transporteurB;
        $this->en($t)->putJson("/api/reservations/{$r->id}/accepter-mission")->assertOk();          // appui 1

        // Loin du producteur : rien d'automatique ; sur place : arrivée enregistrée d'elle-même
        $loin = $this->guider($t, 'reservation', $r->id, 14.70, -17.40);
        $this->assertFalse($loin['arrivee_automatique']);
        $this->assertTrue($this->guider($t, 'reservation', $r->id, 14.8501, -16.8001)['arrivee_automatique']);
        $this->en($t)->putJson("/api/reservations/{$r->id}/etape", ['etape' => 'au_point_collecte'])->assertOk(); // automatique

        // « Démarrer » : chargement et départ en un appui
        $this->assertSame('demarrer', $this->guider($t, 'reservation', $r->id, 14.8501, -16.8001)['action_principale']);
        $this->en($t)->putJson("/api/reservations/{$r->id}/etape", ['etape' => 'chargee'])->assertOk();  // appui 2
        $this->en($t)->putJson("/api/reservations/{$r->id}/etape", ['etape' => 'en_route'])->assertOk();

        $this->assertSame('arriver', $this->guider($t, 'reservation', $r->id, 14.80, -16.95)['action_principale']);
        $this->en($t)->putJson("/api/reservations/{$r->id}/etape", ['etape' => 'arrivee_entrepot'])->assertOk(); // appui 3

        // Toutes les étapes restent datées dans l'historique (traçabilité intacte)
        $this->assertSame('arrivee_entrepot', Reservation::find($r->id)->statut);
        $etapes = \App\Models\HistoriqueOperation::where('sujet_type', 'reservation')->where('sujet_id', $r->id)->orderBy('id')->pluck('nouveau_statut')->all();
        foreach (['confirmee', 'prise_en_charge', 'au_point_collecte', 'chargee', 'en_route', 'arrivee_entrepot'] as $statut) {
            $this->assertContains($statut, $etapes);
        }
    }

    public function test_commande_accepter_puis_prete_et_confier(): void
    {
        $id = $this->en($this->acheteur)->postJson('/api/commandes', ['stock_id' => $this->stock->id, 'quantite' => 5, 'moyen' => 'especes'])->json('commande.id');
        $this->assertSame('confirmer', $this->aFaire($this->gestionnaire, $id)['action']);                          // appui 1
        $this->en($this->gestionnaire)->putJson("/api/commandes/{$id}/confirmer")->assertOk();

        $e = $this->aFaire($this->gestionnaire, $id);
        $this->assertSame('prete_et_confier', $e['action']);                                                       // appui 2
        $this->en($this->gestionnaire)->putJson("/api/commandes/{$id}/preparer")->assertOk();
        $this->en($this->gestionnaire)->putJson("/api/commandes/{$id}/prete")->assertOk();
        $this->en($this->gestionnaire)->postJson('/api/livraisons', ['commande_id' => $id, 'transporteur_id' => $e['transporteur_propose']['id']])->assertStatus(201);

        // Transporteur, à l'entrepôt : « Démarrer » d'emblée
        $c = \App\Models\Commande::find($id);
        $t = $c->livraison->transporteur_id === $this->transporteurA->transporteur->id ? $this->transporteurA : $this->transporteurB;
        $this->en($t)->putJson("/api/livraisons/{$c->livraison->id}/accepter-mission")->assertOk();
        $this->assertSame('demarrer', $this->guider($t, 'livraison', $c->livraison->id, 14.7901, -16.9301)['action_principale']);
    }

    public function test_sans_transporteur_libre_l_etape_simple_reste_proposee(): void
    {
        \App\Models\Utilisateur::where('role', 'transporteur')->update(['statut' => 'bloque']);
        $r = $this->reservation($this->production($this->producteur), $this->gestionnaire, 'en_attente');
        $r->update(['mode_transport' => 'transporteur']);
        $this->assertSame('confirmer', $this->aFaire($this->gestionnaire, $r->id)['action']);

        $prod = $this->reservation($this->production($this->producteur), $this->gestionnaire, 'en_attente');
        $prod->update(['mode_transport' => 'producteur']);
        \App\Models\Utilisateur::where('role', 'transporteur')->update(['statut' => 'actif']);
        $this->assertSame('confirmer', $this->aFaire($this->gestionnaire, $prod->id)['action']);
    }
}

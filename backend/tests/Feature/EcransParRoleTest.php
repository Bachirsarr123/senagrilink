<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Incident;
use App\Models\Livraison;
use App\Models\Reservation;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitLivraison;
use Tests\TestCase;

/**
 * Lot 5 — écrans par rôle : page « À faire » tirée des règles des circuits,
 * fiche de commande commune avec ce que chacun peut voir, tableau des
 * exceptions de l'administrateur, messages d'erreur en français.
 */
class EcransParRoleTest extends TestCase
{
    use ConstruitLivraison, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparerLivraison();
    }

    private function aFaire(Utilisateur $u): array
    {
        return $this->en($u)->getJson('/api/a-faire')->assertOk()->json('elements');
    }

    public function test_a_faire_suit_le_circuit_de_role_en_role(): void
    {
        $c = $this->commandeJusqua('confirmee');
        $this->en($this->acheteur)->postJson('/api/commandes', ['stock_id' => $this->stock->id, 'quantite' => 5])->assertStatus(201);

        // Entrepôt : une commande à préparer, une à confirmer ; rien pour l'acheteur ni les transporteurs
        $g = collect($this->aFaire($this->gestionnaire));
        $this->assertSame(['Commande à confirmer par l\'entrepôt', 'Préparation à lancer'], $g->where('categorie', 'commande')->pluck('etape')->sort()->values()->all());
        $this->assertSame([], $this->aFaire($this->acheteur));
        $this->assertSame([], $this->aFaire($this->transporteurA));

        // Mission proposée : elle passe chez le transporteur A
        $this->en($this->gestionnaire)->putJson("/api/commandes/{$c->id}/preparer")->assertOk();
        $this->en($this->gestionnaire)->putJson("/api/commandes/{$c->id}/prete")->assertOk();
        $this->en($this->gestionnaire)->postJson('/api/livraisons', ['commande_id' => $c->id, 'transporteur_id' => $this->transporteurA->transporteur->id])->assertStatus(201);
        $this->assertSame(['livraison'], array_column($this->aFaire($this->transporteurA), 'categorie'));
        $this->assertSame([], $this->aFaire($this->transporteurB));
        $this->assertNotContains($c->numero_commande, array_column($this->aFaire($this->gestionnaire), 'numero'));

        // Arrivée : c'est à l'acheteur de confirmer la réception
        foreach (['acceptee', 'chargee', 'en_cours', 'arrivee'] as $e) {
            $e === 'acceptee'
                ? $this->en($this->transporteurA)->putJson("/api/livraisons/{$this->livraisonId($c->id)}/accepter-mission")->assertOk()
                : $this->etapeLivraison($this->transporteurA, $c->id, $e)->assertOk();
        }
        $this->assertSame(['Réception à confirmer par l\'acheteur'], array_column($this->aFaire($this->acheteur), 'etape'));
    }

    public function test_a_faire_incidents_et_stocks_en_alerte_pour_le_gestionnaire(): void
    {
        $c = $this->commandeJusqua('en_cours');
        $this->en($this->transporteurA)->postJson('/api/incidents', ['sujet_type' => 'livraison', 'sujet_id' => $this->livraisonId($c->id), 'type' => 'panne', 'description' => 'Moteur'])->assertStatus(201);
        $this->stock->update(['seuil_alerte' => 75]);

        $g = collect($this->aFaire($this->gestionnaire));
        $incident = $g->firstWhere('categorie', 'incident');
        $this->assertSame('Incident à décider : panne', $incident['etape']);
        $this->assertSame('haute', $incident['urgence']);
        // Entrepôt pas encore placé sur la carte : demandé en priorité, puis disparaît une fois placé
        $this->assertSame('Placez votre entrepôt sur la carte', $g->firstWhere('categorie', 'lieu')['titre']);
        $this->gestionnaire->entrepot->update(['latitude' => 14.7, 'longitude' => -17.4]);
        $g = collect($this->aFaire($this->gestionnaire->fresh()));
        $this->assertNull($g->firstWhere('categorie', 'lieu'));
        $this->assertSame('incident', $g->first()['categorie']);
        $this->assertNotNull($g->firstWhere('categorie', 'stock'));
        // Un autre entrepôt ne voit rien
        $this->assertSame(['lieu'], array_column($this->aFaire(Utilisateur::factory()->gestionnaire()->create()), 'categorie'));
    }

    public function test_a_faire_depot_producteur_et_retard(): void
    {
        $r = $this->en($this->producteur)->postJson('/api/reservations', [
            'entrepot_id' => $this->gestionnaire->entrepot->id, 'production_id' => $this->production($this->producteur)->id,
            'quantite_reservee' => 40, 'date_debut' => '2026-11-02', 'mode_transport' => 'producteur',
        ])->assertStatus(201)->json('reservation');

        $this->travelTo(now()->addDays(2));
        $depot = collect($this->aFaire($this->gestionnaire))->firstWhere('numero', $r['numero_reservation']);
        $this->assertSame('Demande à examiner', $depot['etape']);
        $this->assertTrue($depot['en_retard']);
        $this->assertSame([], $this->aFaire($this->producteur));

        $this->en($this->gestionnaire)->putJson("/api/reservations/{$r['id']}/confirmer")->assertOk();
        $this->assertSame(['Le producteur apporte la marchandise'], array_column($this->aFaire($this->producteur), 'etape'));
    }

    public function test_fiche_commande_commune_selon_le_role(): void
    {
        $c   = $this->commandeJusqua('chargee');
        $url = "/api/fiches/commande/{$c->id}";

        $acheteur = $this->en($this->acheteur)->getJson($url)->assertOk()->json();
        $this->assertSame('acheteur', $acheteur['role']);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $acheteur['code_reception']);
        $this->assertSame(1500.0, (float) $acheteur['commande']['prix']);
        $this->assertSame(['Création', 'Confirmée', 'Préparation lancée', 'Prête', 'Création', 'Mission acceptée', 'Chargée', 'Marchandise chargée'],
            array_column($acheteur['etapes'], 'libelle'));
        $this->assertSame($this->acheteur->prenom.' '.$this->acheteur->nom, $acheteur['etapes'][0]['auteur']);
        $this->assertNotNull($acheteur['livraison']['transporteur']['telephone']);

        $transporteur = $this->en($this->transporteurA)->getJson($url)->assertOk()->json();
        $this->assertSame('transporteur', $transporteur['role']);
        $this->assertNull($transporteur['commande']['prix']);
        $this->assertNull($transporteur['code_reception']);
        $this->assertSame(['partir', 'declarer_incident'], $transporteur['actions_livraison']);

        $gestion = $this->en($this->gestionnaire)->getJson($url)->assertOk()->json();
        $this->assertSame('gestionnaire', $gestion['role']);
        $this->assertNull($gestion['code_reception']);
        $this->assertSame('producteur', $this->en($this->producteur)->getJson($url)->json('role'));
        $this->assertNull($this->en($this->producteur)->getJson($url)->json('acheteur'));

        foreach ([Utilisateur::factory()->acheteur()->create(), $this->transporteurB, Utilisateur::factory()->gestionnaire()->create()] as $u) {
            $this->en($u)->getJson($url)->assertStatus(403);
        }
        $this->en($this->acheteur)->getJson('/api/fiches/commande/99999')->assertStatus(404);
    }

    public function test_tableau_des_exceptions_reserve_a_ladministrateur(): void
    {
        $admin = Utilisateur::factory()->administrateur()->create();
        $c = $this->commandeJusqua('en_cours');
        $this->en($this->acheteur)->postJson('/api/commandes', ['stock_id' => $this->stock->id, 'quantite' => 5])->assertStatus(201);
        $this->en($this->transporteurA)->postJson('/api/incidents', ['sujet_type' => 'livraison', 'sujet_id' => $this->livraisonId($c->id), 'type' => 'retard', 'description' => 'Bouchons'])->assertStatus(201);

        $this->travelTo(now()->addDays(3));
        $ex = collect($this->en($admin)->getJson('/api/admin/exceptions')->assertOk()->json('exceptions'));

        $this->assertSame(['gps', 'incident'], $ex->where('gravite', 'haute')->pluck('categorie')->sort()->values()->all());
        $this->assertStringContainsString('sans aucune position', $ex->firstWhere('categorie', 'gps')['message']);
        $this->assertStringContainsString('en attente de confirmation', $ex->firstWhere('categorie', 'commande')['message']);
        $this->en($this->gestionnaire)->getJson('/api/admin/exceptions')->assertStatus(403);
    }

    public function test_messages_derreur_en_francais(): void
    {
        $this->en($this->acheteur)->postJson('/api/commandes', ['stock_id' => 'abc', 'quantite' => -1])
             ->assertStatus(422)
             ->assertJsonPath('errors.stock_id.0', 'Le champ produit doit être un nombre entier.')
             ->assertJsonPath('errors.quantite.0', 'La quantité doit être supérieure à zéro.');
        $this->en($this->transporteurA)->postJson('/api/suivi/livraison/1/positions', [])
             ->assertStatus(422)->assertJsonPath('errors.positions.0', 'Le champ positions est obligatoire.');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\HistoriqueOperation;
use App\Models\Stock;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * Lot 1 — prix au kilo fixé par le producteur et figé sur la commande ;
 * disponible = physique - réservé ; lot verrouillé une fois en entrepôt.
 */
class PrixEtLotTest extends TestCase
{
    use ConstruitParcours, RefreshDatabase;

    private object $p;

    protected function setUp(): void
    {
        parent::setUp();

        $this->p = (object) [
            'producteur'   => Utilisateur::factory()->create(),
            'gestionnaire' => Utilisateur::factory()->gestionnaire()->create(),
            'acheteur'     => Utilisateur::factory()->acheteur()->create(),
        ];
        $this->p->production = $this->production($this->p->producteur); // 150 FCFA/kg
        $this->p->stock = Stock::create([
            'entrepot_id' => $this->p->gestionnaire->entrepot->id, 'production_id' => $this->p->production->id,
            'produit' => 'riz', 'quantite' => 50, 'statut' => 'disponible',
        ]);
        $this->p->production->update(['statut' => 'disponible']);
    }

    private function commander(float $quantite, array $extra = [])
    {
        return $this->en($this->p->acheteur)->postJson('/api/commandes', array_merge(['stock_id' => $this->p->stock->id, 'quantite' => $quantite], $extra));
    }

    public function test_la_recolte_exige_un_prix_au_kilo(): void
    {
        $recolte = ['type_culture' => 'mil', 'date_recolte' => '2026-06-01', 'quantite_estimee' => 10];

        $this->en($this->p->producteur)->postJson('/api/productions', $recolte)->assertStatus(422)->assertJsonValidationErrors('prix_unitaire');
        $this->en($this->p->producteur)->postJson('/api/productions', $recolte + ['prix_unitaire' => 0])->assertJsonValidationErrors('prix_unitaire');
        $this->en($this->p->producteur)->postJson('/api/productions', $recolte + ['prix_unitaire' => 275])
             ->assertStatus(201)->assertJsonPath('production.prix_unitaire', 275);
    }

    public function test_le_prix_vient_du_lot_et_lacheteur_ne_peut_pas_le_fixer(): void
    {
        $this->commander(4, ['prix' => 1])->assertStatus(422)->assertJsonValidationErrors('prix');

        $this->commander(4)->assertStatus(201)
             ->assertJsonPath('commande.prix_unitaire', 150)
             ->assertJsonPath('commande.prix', 600);
    }

    public function test_le_prix_est_fige_a_la_commande(): void
    {
        $id = $this->commander(4)->json('commande.id');

        $this->en($this->p->producteur)->putJson("/api/productions/{$this->p->production->id}", ['prix_unitaire' => 200])->assertOk();

        // La commande existante garde son prix ; sa modification recalcule le montant au prix figé
        $this->en($this->p->acheteur)->putJson("/api/commandes/{$id}", ['quantite' => 6])->assertOk()
             ->assertJsonPath('commande.prix_unitaire', 150)->assertJsonPath('commande.prix', 900);
        $this->en($this->p->acheteur)->putJson("/api/commandes/{$id}", ['prix' => 1])->assertJsonValidationErrors('prix');

        // Une nouvelle commande prend le nouveau prix
        $this->commander(1)->assertJsonPath('commande.prix_unitaire', 200);
    }

    public function test_catalogue_affiche_le_disponible_et_le_prix(): void
    {
        $this->commande($this->p->acheteur, $this->p->stock, 'confirmee'); // 10 réservés

        $this->en($this->p->acheteur)->getJson('/api/catalogue')->assertOk()
             ->assertJsonPath('catalogue.0.quantite', 40)
             ->assertJsonPath('catalogue.0.quantite_disponible', 40)
             ->assertJsonPath('catalogue.0.prix_unitaire', 150);

        // Au-delà du disponible : refusé, même si le physique (50) suffirait
        $this->commander(45)->assertStatus(422)->assertJsonPath('quantite_disponible', 40);
    }

    public function test_catalogue_masque_les_lots_sans_prix_ou_entierement_reserves(): void
    {
        $this->p->production->update(['prix_unitaire' => null]);
        $this->en($this->p->acheteur)->getJson('/api/catalogue')->assertJsonCount(0, 'catalogue');
        $this->commander(1)->assertStatus(422);

        $this->p->production->update(['prix_unitaire' => 150]);
        $this->p->stock->update(['quantite_reservee' => 50]);
        $this->en($this->p->acheteur)->getJson('/api/catalogue')->assertJsonCount(0, 'catalogue');
    }

    public function test_lot_en_entrepot_verrouille_sauf_le_prix(): void
    {
        $id = $this->p->production->id;

        $this->en($this->p->producteur)->putJson("/api/productions/{$id}", ['quantite_estimee' => 999])
             ->assertStatus(422)->assertJsonPath('champs_refuses', ['quantite_estimee']);
        $this->en(Utilisateur::factory()->administrateur()->create())->putJson("/api/productions/{$id}", ['date_recolte' => '2026-01-01'])
             ->assertStatus(422);

        // Le formulaire renvoie les valeurs inchangées avec le nouveau prix : accepté
        $this->en($this->p->producteur)->putJson("/api/productions/{$id}", [
            'type_culture' => 'riz', 'date_recolte' => '2026-06-01', 'quantite_estimee' => 100.0, 'prix_unitaire' => 180,
        ])->assertOk()->assertJsonPath('production.prix_unitaire', 180);

        $this->assertEquals(100, $this->p->production->fresh()->quantite_estimee);
        $modif = HistoriqueOperation::where(['sujet_type' => 'production', 'sujet_id' => $id, 'action' => 'modification'])->sole();
        $this->assertEquals(['prix_unitaire' => 150], $modif->details['avant']);
        $this->assertEquals(['prix_unitaire' => 180], $modif->details['apres']);
    }

    public function test_recolte_pas_encore_en_entrepot_reste_modifiable(): void
    {
        $autre = $this->production($this->p->producteur);

        $this->en($this->p->producteur)->putJson("/api/productions/{$autre->id}", ['quantite_estimee' => 120])
             ->assertOk()->assertJsonPath('production.quantite_estimee', 120);
    }

    public function test_actions_de_ladministrateur_sur_les_comptes_historisees(): void
    {
        $admin = Utilisateur::factory()->administrateur()->create();

        $this->en($admin)->putJson("/api/admin/utilisateurs/{$this->p->acheteur->id}/bloquer", ['motif' => 'Fraude'])->assertOk();
        $this->en($admin)->putJson("/api/admin/utilisateurs/{$this->p->acheteur->id}/debloquer")->assertOk();

        $this->assertSame(['blocage', 'deblocage'], HistoriqueOperation::where(['sujet_type' => 'utilisateur', 'sujet_id' => $this->p->acheteur->id])
            ->orderBy('id')->pluck('action')->all());
        $this->assertSame($admin->id, HistoriqueOperation::where('action', 'blocage')->value('utilisateur_id'));
    }
}

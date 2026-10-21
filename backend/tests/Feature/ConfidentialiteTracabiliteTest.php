<?php

namespace Tests\Feature;

use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * R6 — commandes disponibles : identité de l'acheteur réservée au producteur du lot.
 * R7 — traçabilité ouverte à tous, mais prix et données identifiantes réservés
 *      aux acteurs concernés par chaque commande.
 */
class ConfidentialiteTracabiliteTest extends TestCase
{
    use ConstruitParcours, RefreshDatabase;

    private object $p;

    protected function setUp(): void
    {
        parent::setUp();

        $this->p = $this->parcours('en_cours');
    }

    // ── R6 ────────────────────────────────────────────────────────────────────

    private function commandesDisponibles(Utilisateur $utilisateur): array
    {
        return $this->en($utilisateur)->getJson('/api/commandes/disponibles')
                    ->assertOk()->json('commandes_disponibles');
    }

    public function test_contrat_api_des_commandes_disponibles(): void
    {
        // Clé lue par le web (planifier-ventes, commandes-disponibles) et le mobile
        $this->commande($this->p->acheteur, $this->p->stock);

        $this->en(Utilisateur::factory()->create())
             ->getJson('/api/commandes/disponibles')
             ->assertOk()
             ->assertJsonMissingPath('commandes')
             ->assertJsonStructure(['commandes_disponibles' => [['id', 'numero_commande', 'produit', 'quantite', 'statut', 'acheteur_masque', 'stock']]])
             ->assertJsonCount(1, 'commandes_disponibles');
    }

    public function test_r6_le_producteur_du_lot_voit_lacheteur(): void
    {
        $this->commande($this->p->acheteur, $this->p->stock);

        $commande = $this->commandesDisponibles($this->p->producteur)[0];

        $this->assertSame($this->p->acheteur->nom, $commande['acheteur']['utilisateur']['nom']);
        $this->assertFalse($commande['acheteur_masque']);
    }

    public function test_r6_un_producteur_tiers_voit_la_demande_sans_lacheteur(): void
    {
        $this->commande($this->p->acheteur, $this->p->stock);
        $tiers = Utilisateur::factory()->create();

        $commande = $this->commandesDisponibles($tiers)[0];

        // La demande reste exploitable commercialement…
        $this->assertSame('riz', $commande['produit']);
        $this->assertEquals(10, $commande['quantite']);
        $this->assertSame('Entrepôt test', $commande['stock']['entrepot']['nom_entrepot']);
        // …sans identifier l'acheteur
        $this->assertNull($commande['acheteur']);
        $this->assertArrayNotHasKey('acheteur_id', $commande);
        $this->assertTrue($commande['acheteur_masque']);
    }

    public function test_r6_ladministrateur_voit_lacheteur(): void
    {
        $this->commande($this->p->acheteur, $this->p->stock);
        $admin = Utilisateur::factory()->administrateur()->create();

        $this->assertNotNull($this->commandesDisponibles($admin)[0]['acheteur']);
    }

    // ── R7 ────────────────────────────────────────────────────────────────────

    private function commandeTracee(Utilisateur $utilisateur, int $index = 0): array
    {
        return $this->en($utilisateur)
                    ->getJson("/api/tracabilite/{$this->p->production->code_tracabilite}")
                    ->assertOk()
                    ->json("tracabilite.etapes_stockage.0.commandes.{$index}");
    }

    private function assertComplet(array $commande): void
    {
        $this->assertEquals(1500, $commande['prix']);
        $this->assertSame($this->p->acheteur->nom, $commande['acheteur']['nom']);
        $this->assertSame('Dakar Plateau, rue 10', $commande['livraison']['destination']);
        $this->assertSame($this->p->transporteur->nom, $commande['livraison']['transporteur']['nom']);
        $this->assertFalse($commande['informations_masquees']);
    }

    private function assertMasque(array $commande): void
    {
        $this->assertNull($commande['prix']);
        $this->assertNull($commande['acheteur']);
        $this->assertNull($commande['livraison']['destination']);
        $this->assertNull($commande['livraison']['transporteur']);
        $this->assertTrue($commande['informations_masquees']);
        // Le parcours du lot reste traçable
        $this->assertSame('riz', $commande['produit']);
        $this->assertSame('en_livraison', $commande['statut']);
        $this->assertSame('en_cours', $commande['livraison']['statut']);
    }

    public function test_r7_les_acteurs_concernes_voient_tout(): void
    {
        $admin = Utilisateur::factory()->administrateur()->create();

        foreach ([$this->p->producteur, $this->p->gestionnaire, $this->p->acheteur, $admin] as $acteur) {
            $this->assertComplet($this->commandeTracee($acteur));
        }
    }

    public function test_r7_le_transporteur_voit_lacheteur_et_la_destination_mais_pas_le_prix(): void
    {
        $commande = $this->commandeTracee($this->p->transporteur);

        $this->assertNull($commande['prix']);
        $this->assertSame($this->p->acheteur->nom, $commande['acheteur']['nom']);
        $this->assertSame('Dakar Plateau, rue 10', $commande['livraison']['destination']);
        $this->assertTrue($commande['informations_masquees']);
    }

    public function test_r7_les_tiers_voient_le_parcours_sans_prix_ni_identites(): void
    {
        $tiers = [
            Utilisateur::factory()->create(),
            Utilisateur::factory()->gestionnaire()->create(),
            Utilisateur::factory()->acheteur()->create(),
            Utilisateur::factory()->transporteur()->create(),
        ];

        foreach ($tiers as $utilisateur) {
            $this->assertMasque($this->commandeTracee($utilisateur));

            // L'origine du lot reste publique
            $this->en($utilisateur)
                 ->getJson("/api/tracabilite/{$this->p->production->code_tracabilite}")
                 ->assertJsonPath('tracabilite.etape_production.producteur.nom', $this->p->producteur->nom)
                 ->assertJsonPath('tracabilite.etapes_stockage.0.entrepot.nom', 'Entrepôt test');
        }
    }

    public function test_r7_un_acheteur_ne_voit_que_sa_propre_commande(): void
    {
        $autreAcheteur = Utilisateur::factory()->acheteur()->create();
        $this->commande($autreAcheteur, $this->p->stock, 'en_attente');

        $this->assertComplet($this->commandeTracee($this->p->acheteur, 0));

        $commandeDautrui = $this->commandeTracee($this->p->acheteur, 1);
        $this->assertNull($commandeDautrui['prix']);
        $this->assertNull($commandeDautrui['acheteur']);
        $this->assertTrue($commandeDautrui['informations_masquees']);
    }
}

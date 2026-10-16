<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Livraison;
use App\Models\Stock;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V2 / V3 — un gestionnaire ne peut confirmer une commande ni créer sa livraison
 * que si la commande porte sur un stock de SON entrepôt. L'admin garde l'accès.
 */
class PerimetreGestionnaireTest extends TestCase
{
    use RefreshDatabase;

    private Utilisateur $gestionnaire;
    private Utilisateur $autreGestionnaire;
    private Utilisateur $admin;
    private Utilisateur $acheteur;
    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        // La factory crée l'entrepôt de chaque gestionnaire
        $this->gestionnaire      = Utilisateur::factory()->gestionnaire()->create();
        $this->autreGestionnaire = Utilisateur::factory()->gestionnaire()->create();
        $this->admin             = Utilisateur::factory()->administrateur()->create();
        $this->acheteur          = Utilisateur::factory()->acheteur()->create();

        $this->stock = Stock::create([
            'entrepot_id' => $this->gestionnaire->entrepot->id,
            'produit'     => 'riz',
            'quantite'    => 90,
            'date_entree' => '2026-06-01',
            'statut'      => 'disponible',
        ]);
    }

    /** Le garde Sanctum met l'utilisateur en cache pendant un test : on le réinitialise. */
    private function en(Utilisateur $utilisateur): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($utilisateur->createToken('test')->plainTextToken);
    }

    private function commande(string $statut = 'en_attente', ?int $stockId = null, float $quantite = 10): Commande
    {
        return Commande::create([
            'acheteur_id'   => $this->acheteur->acheteurGros->id,
            'stock_id'      => $stockId ?? $this->stock->id,
            'produit'       => 'riz',
            'quantite'      => $quantite,
            'date_commande' => now(),
            'statut'        => $statut,
        ]);
    }

    private function donneesLivraison(Commande $commande): array
    {
        return ['commande_id' => $commande->id, 'origine' => 'Entrepôt test', 'destination' => 'Dakar'];
    }

    // ── V2 : confirmation de commande ─────────────────────────────────────────

    public function test_gestionnaire_de_lentrepot_confirme_et_le_stock_est_deduit(): void
    {
        $commande = $this->commande();

        $this->en($this->gestionnaire)
             ->putJson("/api/commandes/{$commande->id}/confirmer")
             ->assertOk()
             ->assertJsonPath('commande.statut', 'confirmee');

        $this->assertEquals(80, $this->stock->fresh()->quantite);
    }

    public function test_gestionnaire_dun_autre_entrepot_ne_peut_pas_confirmer(): void
    {
        $commande = $this->commande();

        $this->en($this->autreGestionnaire)
             ->putJson("/api/commandes/{$commande->id}/confirmer")
             ->assertStatus(403);

        $this->assertEquals(90, $this->stock->fresh()->quantite);
        $this->assertSame('disponible', $this->stock->fresh()->statut);
        $this->assertSame('en_attente', $commande->fresh()->statut);
    }

    public function test_autre_gestionnaire_recoit_403_meme_si_le_statut_est_incompatible(): void
    {
        $commande = $this->commande('confirmee');

        // Pas de fuite du statut d'une commande hors périmètre
        $this->en($this->autreGestionnaire)
             ->putJson("/api/commandes/{$commande->id}/confirmer")
             ->assertStatus(403)
             ->assertJsonMissingPath('statut_actuel');
    }

    public function test_administrateur_peut_confirmer(): void
    {
        $commande = $this->commande();

        $this->en($this->admin)
             ->putJson("/api/commandes/{$commande->id}/confirmer")
             ->assertOk();

        $this->assertEquals(80, $this->stock->fresh()->quantite);
    }

    public function test_confirmation_commande_inexistante_retourne_404(): void
    {
        $this->en($this->gestionnaire)
             ->putJson('/api/commandes/999999/confirmer')
             ->assertStatus(404);
    }

    public function test_confirmation_refusee_si_statut_incompatible(): void
    {
        foreach (['confirmee', 'annulee', 'livree'] as $statut) {
            $commande = $this->commande($statut);

            $this->en($this->gestionnaire)
                 ->putJson("/api/commandes/{$commande->id}/confirmer")
                 ->assertStatus(422)
                 ->assertJsonPath('statut_actuel', $statut);
        }

        $this->assertEquals(90, $this->stock->fresh()->quantite);
    }

    public function test_confirmation_refusee_si_stock_insuffisant(): void
    {
        $commande = $this->commande(quantite: 100);

        $this->en($this->gestionnaire)
             ->putJson("/api/commandes/{$commande->id}/confirmer")
             ->assertStatus(422);

        $this->assertEquals(90, $this->stock->fresh()->quantite);
        $this->assertSame('en_attente', $commande->fresh()->statut);
    }

    public function test_commande_sans_stock_refusee_au_gestionnaire_et_422_pour_ladmin(): void
    {
        $commande = $this->commande();
        $commande->forceFill(['stock_id' => null])->save(); // ex. stock supprimé (nullOnDelete)

        $this->en($this->gestionnaire)
             ->putJson("/api/commandes/{$commande->id}/confirmer")
             ->assertStatus(403);

        $this->en($this->admin)
             ->putJson("/api/commandes/{$commande->id}/confirmer")
             ->assertStatus(422)
             ->assertJsonPath('message', 'Aucun stock associé à cette commande.');

        $this->assertSame('en_attente', $commande->fresh()->statut);
    }

    // ── V3 : création de livraison ────────────────────────────────────────────

    public function test_gestionnaire_de_lentrepot_cree_la_livraison(): void
    {
        $commande = $this->commande('confirmee');

        $this->en($this->gestionnaire)
             ->postJson('/api/livraisons', $this->donneesLivraison($commande))
             ->assertStatus(201)
             ->assertJsonPath('livraison.commande_id', $commande->id);

        $this->assertSame(1, Livraison::count());
    }

    public function test_gestionnaire_dun_autre_entrepot_ne_peut_pas_creer_la_livraison(): void
    {
        $commande = $this->commande('confirmee');

        $this->en($this->autreGestionnaire)
             ->postJson('/api/livraisons', $this->donneesLivraison($commande))
             ->assertStatus(403);

        $this->assertSame(0, Livraison::count());
    }

    public function test_administrateur_peut_creer_la_livraison(): void
    {
        $commande = $this->commande('confirmee');

        $this->en($this->admin)
             ->postJson('/api/livraisons', $this->donneesLivraison($commande))
             ->assertStatus(201);

        $this->assertSame(1, Livraison::count());
    }

    public function test_livraison_pour_commande_inexistante_retourne_422(): void
    {
        $this->en($this->gestionnaire)
             ->postJson('/api/livraisons', ['commande_id' => 999999, 'origine' => 'x', 'destination' => 'y'])
             ->assertStatus(422)
             ->assertJsonValidationErrors('commande_id');

        $this->assertSame(0, Livraison::count());
    }

    public function test_livraison_refusee_si_commande_non_confirmee(): void
    {
        $commande = $this->commande('en_attente');

        $this->en($this->gestionnaire)
             ->postJson('/api/livraisons', $this->donneesLivraison($commande))
             ->assertStatus(422)
             ->assertJsonPath('statut_actuel', 'en_attente');

        $this->assertSame(0, Livraison::count());
    }

    public function test_seconde_livraison_pour_la_meme_commande_refusee(): void
    {
        $commande = $this->commande('confirmee');

        $this->en($this->gestionnaire)
             ->postJson('/api/livraisons', $this->donneesLivraison($commande))
             ->assertStatus(201);

        $this->en($this->gestionnaire)
             ->postJson('/api/livraisons', $this->donneesLivraison($commande))
             ->assertStatus(422)
             ->assertJsonPath('message', 'Une livraison existe déjà pour cette commande.');

        // Un gestionnaire hors périmètre reçoit 403, sans information sur la livraison existante
        $this->en($this->autreGestionnaire)
             ->postJson('/api/livraisons', $this->donneesLivraison($commande))
             ->assertStatus(403)
             ->assertJsonMissingPath('numero_livraison');

        $this->assertSame(1, Livraison::count());
    }

    public function test_livraison_pour_commande_sans_stock_refusee_au_gestionnaire(): void
    {
        $commande = $this->commande('confirmee');
        $commande->forceFill(['stock_id' => null])->save();

        $this->en($this->gestionnaire)
             ->postJson('/api/livraisons', $this->donneesLivraison($commande))
             ->assertStatus(403);

        $this->assertSame(0, Livraison::count());
    }
}

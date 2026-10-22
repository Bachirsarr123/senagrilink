<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Production;
use App\Models\Stock;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * P-25 — une soumission répétée (double clic, nouvel essai après une erreur
 * réseau ou un faux échec) ne crée jamais un second enregistrement.
 */
class IdempotenceTest extends TestCase
{
    use ConstruitParcours, RefreshDatabase;

    private const CLE = 'soumission-0001-abcd';

    private function acheteurEtStock(): array
    {
        $gestionnaire = Utilisateur::factory()->gestionnaire()->create();
        $stock = Stock::create([
            'entrepot_id' => $gestionnaire->entrepot->id, 'production_id' => $this->production(Utilisateur::factory()->create())->id,
            'produit' => 'riz', 'quantite' => 50, 'statut' => 'disponible',
        ]);

        return [Utilisateur::factory()->acheteur()->create(), $stock];
    }

    private function commander(Utilisateur $acheteur, Stock $stock, float $quantite, ?string $cle = self::CLE)
    {
        $requete = $this->en($acheteur);

        return ($cle ? $requete->withHeader('Idempotency-Key', $cle) : $requete)
            ->postJson('/api/commandes', ['stock_id' => $stock->id, 'quantite' => $quantite]);
    }

    public function test_une_soumission_repetee_renvoie_la_meme_commande_sans_doublon(): void
    {
        [$acheteur, $stock] = $this->acheteurEtStock();

        $premiere = $this->commander($acheteur, $stock, 5)->assertStatus(201);
        $seconde  = $this->commander($acheteur, $stock, 5)->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, Commande::count());
        $this->assertSame($premiere->json('commande.id'), $seconde->json('commande.id'));
        // Pas de seconde notification non plus
        $this->assertSame(1, $stock->entrepot->utilisateur->notifications()->count());
    }

    public function test_meme_cle_avec_un_autre_contenu_est_refusee(): void
    {
        [$acheteur, $stock] = $this->acheteurEtStock();
        $this->commander($acheteur, $stock, 5)->assertStatus(201);

        $this->commander($acheteur, $stock, 7)->assertStatus(422);
        $this->assertSame(1, Commande::count());
    }

    public function test_un_echec_nest_pas_memorise_la_soumission_corrigee_aboutit(): void
    {
        [$acheteur, $stock] = $this->acheteurEtStock();

        $this->commander($acheteur, $stock, 500)->assertStatus(422); // stock insuffisant
        $this->commander($acheteur, $stock, 5)->assertStatus(201);   // même clé, saisie corrigée
        $this->assertSame(1, Commande::count());
    }

    public function test_requete_identique_encore_en_cours_repond_409(): void
    {
        [$acheteur, $stock] = $this->acheteurEtStock();
        $corps = json_encode(['stock_id' => $stock->id, 'quantite' => 5]);
        DB::table('requetes_idempotentes')->insert([
            'utilisateur_id' => $acheteur->id, 'cle' => self::CLE,
            'empreinte' => hash('sha256', 'POST api/commandes '.$corps), 'created_at' => now(),
        ]);

        $this->commander($acheteur, $stock, 5)->assertStatus(409)->assertJsonPath('code', 'requete_en_cours');
        $this->assertSame(0, Commande::count());
    }

    public function test_tentative_interrompue_signalee_sans_retraitement_automatique(): void
    {
        [$acheteur, $stock] = $this->acheteurEtStock();
        $corps = json_encode(['stock_id' => $stock->id, 'quantite' => 5]);
        // Requête restée « en cours » : serveur redémarré pendant le traitement.
        DB::table('requetes_idempotentes')->insert([
            'utilisateur_id' => $acheteur->id, 'cle' => self::CLE,
            'empreinte' => hash('sha256', 'POST api/commandes '.$corps), 'created_at' => now()->subMinutes(5),
        ]);

        $this->commander($acheteur, $stock, 5)->assertStatus(409)->assertJsonPath('code', 'tentative_interrompue');
        $this->assertSame(0, Commande::count()); // jamais retraitée automatiquement

        // Formulaire rouvert : nouvelle clé, la commande peut être passée.
        $this->commander($acheteur, $stock, 5, 'nouvelle-cle-0002')->assertStatus(201);
    }

    public function test_les_cles_sont_propres_a_chaque_utilisateur(): void
    {
        [$acheteur, $stock] = $this->acheteurEtStock();
        $autre = Utilisateur::factory()->acheteur()->create();

        $this->commander($acheteur, $stock, 5)->assertStatus(201);
        $this->commander($autre, $stock, 5)->assertStatus(201);
        $this->assertSame(2, Commande::count());
    }

    public function test_sans_cle_le_comportement_reste_inchange(): void
    {
        [$acheteur, $stock] = $this->acheteurEtStock();

        $this->commander($acheteur, $stock, 5, null)->assertStatus(201);
        $this->commander($acheteur, $stock, 5, null)->assertStatus(201);
        $this->assertSame(2, Commande::count());
    }

    public function test_cle_mal_formee_refusee(): void
    {
        [$acheteur, $stock] = $this->acheteurEtStock();
        $this->commander($acheteur, $stock, 5, 'x')->assertStatus(400);
        $this->assertSame(0, Commande::count());
    }

    public function test_recolte_non_dupliquee(): void
    {
        $producteur = Utilisateur::factory()->create();
        $corps = ['type_culture' => 'mil', 'date_recolte' => '2026-06-01', 'quantite_estimee' => 10, 'prix_unitaire' => 300];

        foreach ([1, 2] as $_) {
            $this->en($producteur)->withHeader('Idempotency-Key', self::CLE)->postJson('/api/productions', $corps)->assertStatus(201);
        }
        $this->assertSame(1, Production::count());
    }

    public function test_les_traces_de_plus_de_24h_sont_purgees(): void
    {
        [$acheteur, $stock] = $this->acheteurEtStock();
        DB::table('requetes_idempotentes')->insert([
            'utilisateur_id' => $acheteur->id, 'cle' => 'ancienne-cle-0001', 'empreinte' => str_repeat('a', 64),
            'statut_http' => 201, 'reponse' => '{}', 'created_at' => now()->subHours(25),
        ]);

        $this->commander($acheteur, $stock, 5)->assertStatus(201);
        $this->assertDatabaseMissing('requetes_idempotentes', ['cle' => 'ancienne-cle-0001']);
    }
}

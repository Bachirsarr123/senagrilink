<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Incident;
use App\Models\Livraison;
use App\Models\Stock;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/** Migration 000021 sur des commandes et livraisons créées avant le lot 3. */
class RepriseCircuitLivraisonTest extends TestCase
{
    use ConstruitParcours, DatabaseMigrations;

    private const MIGRATION = 'database/migrations/2025_01_01_000021_create_circuit_livraison.php';

    public function test_reprise_des_commandes_et_livraisons_existantes(): void
    {
        $gestionnaire = Utilisateur::factory()->gestionnaire()->create();
        $acheteur     = Utilisateur::factory()->acheteur()->create();
        $transporteur = Utilisateur::factory()->transporteur()->create();
        $lot          = $this->production(Utilisateur::factory()->create());
        $stock        = Stock::create(['entrepot_id' => $gestionnaire->entrepot->id, 'production_id' => $lot->id, 'produit' => 'riz', 'quantite' => 80, 'quantite_reservee' => 40, 'statut' => 'disponible']);

        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);

        $commande = fn (string $statut) => DB::table('commandes')->insertGetId([
            'numero_commande' => 'CMD-'.uniqid(), 'acheteur_id' => $acheteur->acheteurGros->id, 'stock_id' => $stock->id, 'produit' => 'riz',
            'quantite' => 10, 'prix_unitaire' => 150, 'prix' => 1500, 'date_commande' => now(), 'statut' => $statut, 'etat_stock' => 'reserve',
        ]);
        $livraison = fn (int $c, string $statut) => DB::table('livraisons')->insertGetId([
            'numero_livraison' => 'LIV-'.uniqid(), 'commande_id' => $c, 'transporteur_id' => $transporteur->transporteur->id,
            'origine' => 'Entrepôt', 'destination' => 'Dakar', 'statut' => $statut, 'date_depart' => now(),
        ]);
        $livree    = $commande('livree');
        $aAccepter = $commande('confirmee');
        $partie    = $commande('confirmee');
        $probleme  = $commande('confirmee');
        $livraison($livree, 'livree');
        $lAccepter = $livraison($aAccepter, 'en_attente');
        $livraison($partie, 'en_cours');
        $lProbleme = $livraison($probleme, 'probleme');

        Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]);

        $this->assertSame(['receptionnee', 'prete', 'en_livraison', 'en_livraison'],
            [Commande::find($livree)->statut, Commande::find($aAccepter)->statut, Commande::find($partie)->statut, Commande::find($probleme)->statut]);
        $this->assertSame('en_cours', Livraison::find($lProbleme)->statut);
        $this->assertSame(['livraison', $lProbleme, 'ouvert'], [Incident::sole()->sujet_type, Incident::sole()->sujet_id, Incident::sole()->statut]);
        $this->assertNotNull(Livraison::where('commande_id', $partie)->sole()->mission_acceptee_le);

        // Le circuit reprend : la mission en attente s'accepte, puis se charge
        $this->en($transporteur)->putJson("/api/livraisons/{$lAccepter}/accepter-mission")->assertOk();
        $this->en($transporteur)->putJson("/api/livraisons/{$lAccepter}/etape", ['etape' => 'chargee'])->assertOk();
        $this->assertSame('en_livraison', Commande::find($aAccepter)->statut);

        // Retour arrière : les anciens statuts reviennent
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);
        $this->assertSame('probleme', DB::table('livraisons')->where('id', $lProbleme)->value('statut'));
        $this->assertSame('livree', DB::table('commandes')->where('id', $livree)->value('statut'));
        $this->assertSame('confirmee', DB::table('commandes')->where('id', $aAccepter)->value('statut'));
    }
}

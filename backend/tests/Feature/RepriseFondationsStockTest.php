<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Livraison;
use App\Models\Stock;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * Migration 000019 sur des données créées avec les anciennes règles (stock
 * déduit à la confirmation) : aucune quantité n'est perdue ni déduite deux fois.
 */
class RepriseFondationsStockTest extends TestCase
{
    use ConstruitParcours, DatabaseMigrations;

    private const MIGRATION = 'database/migrations/2025_01_01_000019_create_fondations_stock.php';

    public function test_reprise_des_commandes_confirmees_et_des_stocks(): void
    {
        $producteur   = Utilisateur::factory()->create();
        $gestionnaire = Utilisateur::factory()->gestionnaire()->create();
        $acheteur     = Utilisateur::factory()->acheteur()->create();
        $transporteur = Utilisateur::factory()->transporteur()->create();
        $production   = $this->production($producteur);

        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);

        // Anciennes règles : 100 kg reçus ; A (20 kg) confirmée sans livraison et
        // B (30 kg) confirmée et partie ont été déduites ; C livrée (10 kg).
        $stockId = DB::table('stocks')->insertGetId([
            'entrepot_id' => $gestionnaire->entrepot->id, 'production_id' => $production->id,
            'produit' => 'riz', 'quantite' => 40, 'statut' => 'disponible',
        ]);
        $commande = fn (float $q) => DB::table('commandes')->insertGetId([
            'acheteur_id' => $acheteur->acheteurGros->id, 'stock_id' => $stockId, 'produit' => 'riz',
            'quantite' => $q, 'date_commande' => now(), 'statut' => 'confirmee',
        ]);
        [$a, $b, $c] = [$commande(20), $commande(30), $commande(10)];
        foreach ([[$b, 'en_cours'], [$c, 'livree']] as [$id, $statut]) {
            DB::table('livraisons')->insert([
                'commande_id' => $id, 'transporteur_id' => $transporteur->transporteur->id,
                'origine' => 'E', 'destination' => 'D', 'statut' => $statut,
            ]);
        }

        Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]);

        $stock = Stock::findOrFail($stockId);
        $this->assertEquals(60, $stock->quantite);            // 40 + 20 de A, encore en entrepôt
        $this->assertEquals(20, $stock->quantite_reservee);   // A réservée
        $this->assertEquals(40, $stock->quantite_disponible); // inchangé pour les acheteurs
        $this->assertSame(['reserve', 'sorti', 'sorti'], [Commande::find($a)->etat_stock, Commande::find($b)->etat_stock, Commande::find($c)->etat_stock]);
        $this->assertSame('livree', Commande::find($c)->statut);
        $this->assertSame(['ouverture', 'reservation'], $stock->mouvements()->pluck('type')->all());
        $this->assertEquals($stock->quantite, $stock->mouvements()->sum('variation_physique'));

        // Le départ de A sort sa quantité une seule fois
        $livraisonA = Livraison::create([
            'commande_id' => $a, 'transporteur_id' => $transporteur->transporteur->id,
            'origine' => 'E', 'destination' => 'D', 'statut' => 'en_attente',
        ]);
        $this->en($transporteur)->putJson("/api/livraisons/{$livraisonA->id}/statut", ['statut' => 'en_cours'])->assertOk();
        $this->assertEquals([40.0, 0.0], [$stock->fresh()->quantite, $stock->fresh()->quantite_reservee]);
    }
}

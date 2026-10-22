<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Stock;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/** Migration 000027 : les commandes d'avant les paiements restent traitables par l'entrepôt. */
class ReprisePaiementCommandesTest extends TestCase
{
    use ConstruitParcours, RefreshDatabase;

    public function test_commandes_d_avant_les_paiements(): void
    {
        $g = Utilisateur::factory()->gestionnaire()->create();
        $a = Utilisateur::factory()->acheteur()->create();
        $stock = Stock::create(['entrepot_id' => $g->entrepot->id, 'production_id' => $this->production(Utilisateur::factory()->create())->id,
            'produit' => 'riz', 'quantite' => 80, 'statut' => 'disponible']);
        $creer = fn (string $statut, array $extra = []) => Commande::create([
            'acheteur_id' => $a->acheteurGros->id, 'stock_id' => $stock->id, 'produit' => 'riz', 'quantite' => 10, 'prix_unitaire' => 150,
            'prix' => 1500, 'date_commande' => now(), 'statut' => $statut, ...$extra,
        ]);
        $attente = $creer('en_attente');
        $recue = $creer('receptionnee');
        $annulee = $creer('annulee');
        $nouvelle = $creer('en_attente', ['total_a_payer' => 2700]); // passée depuis les paiements : intacte
        $this->assertTrue($attente->fresh()->attendPaiement()); // bloquée sans la reprise

        (require base_path('database/migrations/2025_01_01_000027_reprise_paiement_commandes_existantes.php'))->up();

        $this->assertSame(['a_la_livraison', 'encaisse', 'annule', 'a_payer'],
            array_map(fn ($c) => $c->fresh()->statut_paiement, [$attente, $recue, $annulee, $nouvelle]));
        $this->en($g)->putJson("/api/commandes/{$attente->id}/confirmer")->assertOk();
        $this->en($g)->putJson("/api/commandes/{$nouvelle->id}/confirmer")->assertStatus(422);
    }
}

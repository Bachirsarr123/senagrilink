<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Stock;
use App\Models\Utilisateur;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * H1 — une commande n'est confirmée (et sa quantité réservée) qu'une seule fois.
 * La confirmation réserve : le stock physique ne bouge pas, le disponible baisse.
 *
 * Ces tests sont séquentiels : ils prouvent la revérification dans la
 * transaction, pas le verrouillage. SQLite ignore FOR UPDATE ; la concurrence
 * réelle est vérifiée à part sur PostgreSQL (voir compte rendu).
 */
class ConfirmationCommandeTest extends TestCase
{
    use RefreshDatabase;

    private Utilisateur $gestionnaire;
    private Stock $stock;
    private Commande $commande;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gestionnaire = Utilisateur::factory()->gestionnaire()->create();
        $acheteur           = Utilisateur::factory()->acheteur()->create();

        $this->stock = Stock::create([
            'entrepot_id' => $this->gestionnaire->entrepot->id,
            'produit'     => 'riz',
            'quantite'    => 90,
            'statut'      => 'disponible',
        ]);

        $this->commande = Commande::create([
            'acheteur_id'   => $acheteur->acheteurGros->id,
            'stock_id'      => $this->stock->id,
            'produit'       => 'riz',
            'quantite'      => 10,
            'date_commande' => now(),
            'statut'        => 'en_attente',
        ]);
    }

    private function confirmer()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->gestionnaire->createToken('test')->plainTextToken)
                    ->putJson("/api/commandes/{$this->commande->id}/confirmer");
    }

    /** Simule une autre requête qui modifie les données juste avant notre transaction. */
    private function avantLaTransaction(callable $modification): void
    {
        $fait = false;
        Event::listen(TransactionBeginning::class, function () use (&$fait, $modification) {
            if (! $fait) {
                $fait = true;
                $modification();
            }
        });
    }

    public function test_double_confirmation_ne_deduit_quune_fois(): void
    {
        $this->confirmer()->assertOk()->assertJsonPath('stock_restant', 80);
        $this->confirmer()->assertStatus(422)->assertJsonPath('statut_actuel', 'confirmee');

        $stock = $this->stock->fresh();
        $this->assertEquals(90, $stock->quantite);
        $this->assertEquals(10, $stock->quantite_reservee);
        $this->assertSame('reserve', $this->commande->fresh()->etat_stock);
        $this->assertSame(1, $stock->mouvements()->where('type', 'reservation')->count());
    }

    public function test_statut_reverifie_dans_la_transaction(): void
    {
        // Une confirmation concurrente aboutit entre nos contrôles préalables et
        // notre transaction : elle a déjà confirmé et réservé 10.
        $this->avantLaTransaction(function () {
            DB::table('commandes')->where('id', $this->commande->id)->update(['statut' => 'confirmee']);
            DB::table('stocks')->where('id', $this->stock->id)->increment('quantite_reservee', 10);
        });

        $this->confirmer()
             ->assertStatus(422)
             ->assertJsonPath('statut_actuel', 'confirmee');

        $this->assertEquals(10, $this->stock->fresh()->quantite_reservee); // et non 20
    }

    public function test_quantite_reverifiee_dans_la_transaction(): void
    {
        // Le stock baisse (autre opération) entre les contrôles et la transaction.
        $this->avantLaTransaction(function () {
            DB::table('stocks')->where('id', $this->stock->id)->update(['quantite' => 5]);
        });

        $this->confirmer()->assertStatus(422);

        $this->assertEquals(5, $this->stock->fresh()->quantite);
        $this->assertSame('en_attente', $this->commande->fresh()->statut);
    }

    public function test_confirmation_qui_reserve_tout_le_disponible(): void
    {
        $this->commande->update(['quantite' => 90]);

        $this->confirmer()->assertOk()->assertJsonPath('stock_restant', 0);

        // Physiquement encore en entrepôt (statut disponible), mais plus rien à vendre.
        $stock = $this->stock->fresh();
        $this->assertSame('disponible', $stock->statut);
        $this->assertEquals(0, $stock->quantite_disponible);
        $this->assertSame('confirmee', $this->commande->fresh()->statut);
    }

    public function test_reservations_cumulees_ne_depassent_pas_le_stock(): void
    {
        $autre = Commande::create([
            'acheteur_id' => $this->commande->acheteur_id, 'stock_id' => $this->stock->id, 'produit' => 'riz',
            'quantite' => 85, 'date_commande' => now(), 'statut' => 'en_attente',
        ]);

        $this->confirmer()->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->gestionnaire->createToken('test')->plainTextToken)
             ->putJson("/api/commandes/{$autre->id}/confirmer")
             ->assertStatus(422)
             ->assertJsonPath('quantite_disponible', 80);

        $this->assertEquals(10, $this->stock->fresh()->quantite_reservee);
        $this->assertSame('en_attente', $autre->fresh()->statut);
    }
}

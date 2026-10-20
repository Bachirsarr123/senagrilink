<?php

namespace Tests\Feature;

use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * P-07 — quantités, prix, capacités et coordonnées sont renvoyés comme des
 * nombres JSON, quel que soit le moteur. Sous PostgreSQL (production), les
 * colonnes float/decimal arrivaient en chaînes (« 12.5 ») : l'application
 * Flutter, qui lit `as num`, levait une erreur de type à chaque liste.
 *
 * À exécuter aussi sous PostgreSQL : SQLite renvoie déjà des nombres.
 */
class TypesNumeriquesJsonTest extends TestCase
{
    use ConstruitParcours, RefreshDatabase;

    private function assertNombres(TestResponse $reponse, array $chemins): void
    {
        foreach ($chemins as $chemin) {
            $valeur = $reponse->json($chemin);
            $this->assertTrue(is_int($valeur) || is_float($valeur), "« $chemin » devrait être un nombre JSON, reçu : ".var_export($valeur, true));
        }
    }

    public function test_les_listes_lues_par_les_clients_renvoient_des_nombres(): void
    {
        $p = $this->parcours('en_cours');
        $p->stock->update(['seuil_alerte' => 12.5, 'quantite' => 80.5]);
        $p->commande->update(['prix' => 1500.5]);
        $p->production->update(['superficie' => 1.5, 'quantite_reelle' => 95.5]);
        $p->gestionnaire->entrepot->update(['capacite' => 500, 'latitude' => 14.7, 'longitude' => -17.4]);
        $p->livraison->update(['destination_latitude' => 14.69, 'destination_longitude' => -17.44]);

        $this->assertNombres($this->en($p->gestionnaire)->getJson('/api/stocks')->assertOk(),
            ['stocks.0.quantite', 'stocks.0.seuil_alerte']);
        $this->assertNombres($this->en($p->acheteur)->getJson('/api/catalogue')->assertOk(),
            ['catalogue.0.quantite']);
        $this->assertNombres($this->en($p->acheteur)->getJson('/api/commandes/historique')->assertOk(),
            ['commandes.0.quantite', 'commandes.0.prix']);
        $this->assertNombres($this->en($p->producteur)->getJson('/api/productions')->assertOk(),
            ['productions.0.quantite_estimee', 'productions.0.quantite_reelle', 'productions.0.superficie']);
        $this->assertNombres($this->en($p->producteur)->getJson('/api/reservations')->assertOk(),
            ['reservations.0.quantite_reservee']);
        $this->assertNombres($this->en($p->gestionnaire)->getJson('/api/commandes')->assertOk(),
            ['commandes.0.quantite', 'commandes.0.prix']);
        \App\Models\PositionGps::create([
            'transporteur_id' => $p->transporteur->transporteur->id, 'latitude' => 14.71, 'longitude' => -17.45, 'timestamp' => now(),
        ]);
        $this->assertNombres($this->en($p->acheteur)->getJson("/api/livraisons/{$p->livraison->id}/position")->assertOk(),
            ['origine.latitude', 'origine.longitude', 'destination.latitude', 'destination.longitude', 'position.latitude']);
    }

    public function test_capacite_des_entrepots_disponibles(): void
    {
        $g = Utilisateur::factory()->gestionnaire()->create();
        $g->entrepot->update(['capacite' => 300.5]);

        $this->assertNombres($this->en(Utilisateur::factory()->create())->getJson('/api/entrepots/disponibles')->assertOk(),
            ['entrepots.0.capacite', 'entrepots.0.capacite_disponible']);
    }
}

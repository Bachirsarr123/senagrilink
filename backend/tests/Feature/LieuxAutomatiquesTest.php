<?php

namespace Tests\Feature;

use App\Models\Entrepot;
use App\Models\Reservation;
use App\Support\SuiviGps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitLivraison;
use Tests\TestCase;

/** Lot 8 : lieux pris automatiquement, mémorisés au profil, modifiables à chaque mission. */
class LieuxAutomatiquesTest extends TestCase
{
    use ConstruitLivraison;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparerLivraison();
    }

    private function depot(array $lieu = [], string $mode = 'transporteur')
    {
        return $this->en($this->producteur)->postJson('/api/reservations', [
            'entrepot_id' => $this->gestionnaire->entrepot->id, 'production_id' => $this->production($this->producteur)->id,
            'mode_transport' => $mode, 'quantite_reservee' => 5, 'date_debut' => now()->toDateString(), ...$lieu,
        ]);
    }

    public function test_lieu_de_prise_en_charge_du_depot(): void
    {
        // Position du téléphone envoyée : gardée sur le dépôt et mémorisée comme lieu habituel
        $id = $this->depot(['collecte_latitude' => 14.75, 'collecte_longitude' => -17.38])->assertStatus(201)->json('reservation.id');
        $r = Reservation::find($id);
        $this->assertSame([14.75, -17.38], [$r->collecte_latitude, $r->collecte_longitude]);
        $this->assertSame(14.75, $this->producteur->producteur->fresh()->latitude);
        $this->assertSame(14.75, SuiviGps::extremites($r)['origine']['latitude']);

        // Dépôt suivant sans position (GPS indisponible) : le lieu habituel est repris
        $id2 = $this->depot()->assertStatus(201)->json('reservation.id');
        $this->assertSame(-17.38, Reservation::find($id2)->collecte_longitude);

        // Autre lieu ce jour-là : gardé sur ce dépôt, le lieu habituel ne change pas
        $id3 = $this->depot(['collecte_latitude' => 14.1, 'collecte_longitude' => -16.1])->assertStatus(201)->json('reservation.id');
        $this->assertSame(14.1, Reservation::find($id3)->collecte_latitude);
        $this->assertSame(14.75, $this->producteur->producteur->fresh()->latitude);

        // Le producteur livre lui-même : pas de prise en charge
        $id4 = $this->depot(['collecte_latitude' => 14.2, 'collecte_longitude' => -16.2], 'producteur')->assertStatus(201)->json('reservation.id');
        $this->assertNull(Reservation::find($id4)->collecte_latitude);

        // Coordonnée incomplète ou hors limites : refusée
        $this->depot(['collecte_latitude' => 14.2])->assertStatus(422)->assertJsonValidationErrors('collecte_longitude');
        $this->depot(['collecte_latitude' => 99, 'collecte_longitude' => 1])->assertStatus(422)->assertJsonValidationErrors('collecte_latitude');
    }

    public function test_entrepots_les_plus_proches_d_abord(): void
    {
        $this->gestionnaire->entrepot->update(['latitude' => 14.0, 'longitude' => -16.0]);        // loin de Dakar
        $proche = Entrepot::create(['utilisateur_id' => \App\Models\Utilisateur::factory()->gestionnaire()->create()->id, 'nom_entrepot' => 'Proche', 'capacite' => 500, 'latitude' => 14.70, 'longitude' => -17.45]);
        \App\Models\Entrepot::where('id', '!=', $proche->id)->where('id', '!=', $this->gestionnaire->entrepot->id)->delete();

        $liste = $this->en($this->producteur)->getJson('/api/entrepots/disponibles?latitude=14.69&longitude=-17.44')->assertOk()->json('entrepots');
        $this->assertSame('Proche', $liste[0]['nom_entrepot']);
        $this->assertLessThan(2, $liste[0]['distance_km']);
        $this->assertGreaterThan(100, $liste[1]['distance_km']);
        $this->assertSame(14.7, $liste[0]['latitude']);
    }

    public function test_lieu_de_livraison_de_la_commande(): void
    {
        $id = $this->en($this->acheteur)->postJson('/api/commandes', [
            'stock_id' => $this->stock->id, 'quantite' => 5, 'livraison_latitude' => 14.72, 'livraison_longitude' => -17.47, 'livraison_adresse' => 'Marché Castors', 'moyen' => 'especes',
        ])->assertStatus(201)->json('commande.id');
        $this->assertSame(14.72, $this->acheteur->acheteurGros->fresh()->latitude);

        // La livraison organisée part vers le lieu choisi à la commande
        $this->en($this->gestionnaire)->putJson("/api/commandes/{$id}/confirmer")->assertOk();
        $this->en($this->gestionnaire)->putJson("/api/commandes/{$id}/preparer")->assertOk();
        $this->en($this->gestionnaire)->putJson("/api/commandes/{$id}/prete")->assertOk();
        $this->en($this->gestionnaire)->postJson('/api/livraisons', ['commande_id' => $id, 'transporteur_id' => $this->transporteurA->transporteur->id])->assertStatus(201);
        $l = \App\Models\Commande::find($id)->livraison;
        $this->assertSame([14.72, -17.47, 'Marché Castors'], [(float) $l->destination_latitude, (float) $l->destination_longitude, $l->destination]);

        // Commande suivante sans position : lieu habituel de l'acheteur
        $c2 = $this->commandeJusqua('organisee', 2);
        $this->assertSame(-17.47, (float) $c2->livraison->destination_longitude);
    }

    public function test_entrepot_a_placer_sur_la_carte_dans_a_faire(): void
    {
        $this->assertSame('lieu', $this->en($this->gestionnaire)->getJson('/api/a-faire')->json('elements.0.categorie'));
        $this->en($this->gestionnaire)->putJson('/api/profile', ['latitude' => 14.7, 'longitude' => -17.4])->assertOk();
        $this->assertNotContains('lieu', array_column($this->en($this->gestionnaire)->getJson('/api/a-faire')->json('elements'), 'categorie'));
    }
}

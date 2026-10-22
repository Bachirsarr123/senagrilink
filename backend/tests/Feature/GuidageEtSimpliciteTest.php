<?php

namespace Tests\Feature;

use App\Models\PositionGps;
use App\Models\Reservation;
use App\Models\Utilisateur;
use App\Services\Itineraire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConstruitLivraison;
use Tests\TestCase;

/** Lot 9 : guidage dans l'application, transporteur proposé, action d'un appui depuis « À faire ». */
class GuidageEtSimpliciteTest extends TestCase
{
    use ConstruitLivraison, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparerLivraison();
        $this->gestionnaire->entrepot->update(['latitude' => 14.79, 'longitude' => -16.93]);   // Thiès
        $this->acheteur->acheteurGros->update(['latitude' => 14.69, 'longitude' => -17.44]); // Dakar
    }

    private function guider(Utilisateur $u, string $type, int $id, float $lat = 14.80, float $lng = -16.95)
    {
        return $this->en($u)->getJson("/api/navigation/{$type}/{$id}?latitude={$lat}&longitude={$lng}");
    }

    public function test_guidage_openrouteservice_vers_l_entrepot_puis_vers_l_acheteur(): void
    {
        config(['services.routage.cle' => 'cle-test']);
        Http::fake(['api.openrouteservice.org/*' => Http::response(['features' => [[
            'geometry' => ['coordinates' => [[-16.95, 14.80], [-16.94, 14.795], [-16.93, 14.79]]],
            'properties' => ['summary' => ['distance' => 2300, 'duration' => 300], 'segments' => [['steps' => [
                ['instruction' => 'Tournez à droite sur Route de Dakar', 'type' => 1, 'distance' => 2200, 'duration' => 290, 'way_points' => [0, 1]],
                ['instruction' => 'Arrivée à destination', 'type' => 10, 'distance' => 0, 'duration' => 0, 'way_points' => [2, 2]],
            ]]]],
        ]]])]);

        $c = $this->commandeJusqua('acceptee');
        $lid = $this->livraisonId($c->id);

        // Mission acceptée, pas encore chargée : guidé vers l'entrepôt
        $vue = $this->guider($this->transporteurA, 'livraison', $lid)->assertOk()->json();
        $this->assertSame('origine', $vue['vers']);
        $this->assertSame('Tournez à droite sur Route de Dakar', $vue['itineraire']['etapes'][0]['instruction']);
        $this->assertSame('droite', $vue['itineraire']['etapes'][0]['type']);
        $this->assertTrue($vue['itineraire']['routier']);
        $this->assertSame(2.3, $vue['itineraire']['km']);
        $this->assertContains('charger', $vue['actions']);
        $this->assertFalse($vue['proche']);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'cle-test') && $r['language'] === 'fr'
            && $r['coordinates'] === [[-16.95, 14.8], [-16.93, 14.79]]);

        // Sur place : « Vous êtes arrivé ? »
        $this->assertTrue($this->guider($this->transporteurA, 'livraison', $lid, 14.7905, -16.9305)->json('proche'));

        // Chargée : guidé vers l'acheteur
        $this->etapeLivraison($this->transporteurA, $c->id, 'chargee')->assertOk();
        $this->assertSame('destination', $this->guider($this->transporteurA, 'livraison', $lid)->json('vers'));

        // Personne d'autre n'est guidé ; la position envoyée n'est pas gardée
        $this->guider($this->transporteurB, 'livraison', $lid)->assertStatus(403);
        $this->guider($this->acheteur, 'livraison', $lid)->assertStatus(403);
        $this->assertSame(0, PositionGps::count());
    }

    public function test_sans_service_de_routage_une_consigne_de_direction(): void
    {
        $c = $this->commandeJusqua('chargee');
        $vue = $this->guider($this->transporteurA, 'livraison', $this->livraisonId($c->id))->assertOk()->json('itineraire');
        $this->assertFalse($vue['routier']);
        $this->assertStringStartsWith("Dirigez-vous vers l'ouest", $vue['etapes'][0]['instruction']);
        $this->assertCount(2, $vue['points']);
    }

    public function test_consignes_osrm_en_francais(): void
    {
        $this->assertSame(['droite', 'Tournez à droite sur Avenue Blaise Diagne'], Itineraire::consigneOsrm(['type' => 'turn', 'modifier' => 'right'], 'Avenue Blaise Diagne'));
        $this->assertSame(['rond_point', 'Au rond-point, prenez la 2e sortie'], Itineraire::consigneOsrm(['type' => 'roundabout', 'exit' => 2], ''));
        $this->assertSame('1,2 km', Itineraire::distanceLisible(1234));
        $this->assertSame('240 m', Itineraire::distanceLisible(237));
    }

    public function test_depot_guide_vers_le_lieu_de_prise_en_charge(): void
    {
        $production = $this->production($this->producteur);
        $r = $this->reservation($production, $this->gestionnaire, 'confirmee');
        $r->update(['transporteur_id' => $this->transporteurA->transporteur->id, 'mode_transport' => 'transporteur',
            'collecte_latitude' => 14.85, 'collecte_longitude' => -16.80]);
        $this->guider($this->transporteurA, 'reservation', $r->id)->assertStatus(403); // pas encore acceptée

        $this->en($this->transporteurA)->putJson("/api/reservations/{$r->id}/accepter-mission")->assertOk();
        $vue = $this->guider($this->transporteurA, 'reservation', $r->id)->assertOk()->json();
        $this->assertSame(['origine', 14.85], [$vue['vers'], $vue['cible']['latitude']]);
        $this->assertContains('arriver_collecte', $vue['actions']);

        // Lieu inconnu : message clair, pas d'erreur
        Reservation::whereKey($r->id)->update(['collecte_latitude' => null, 'collecte_longitude' => null]);
        $this->assertStringContainsString('appelez votre contact', $this->guider($this->transporteurA, 'reservation', $r->id)->json('message'));
    }

    public function test_transporteur_libre_le_plus_proche_propose_et_action_d_un_appui(): void
    {
        // B est libre et a été vu près de Thiès ; A est occupé par une livraison
        PositionGps::create(['utilisateur_id' => $this->transporteurB->id, 'transporteur_id' => $this->transporteurB->transporteur->id,
            'latitude' => 14.80, 'longitude' => -16.94, 'timestamp' => now()->subHour()]);
        $this->commandeJusqua('acceptee', 5);
        $c = $this->commandeJusqua('prete', 5);

        $liste = $this->en($this->gestionnaire)->getJson('/api/transporteurs?latitude=14.79&longitude=-16.93')->assertOk()->json('transporteurs');
        $this->assertSame($this->transporteurB->transporteur->id, $liste[0]['id']);
        $this->assertSame(1.5, $liste[0]['distance_km']);

        $e = collect($this->en($this->gestionnaire)->getJson('/api/a-faire')->json('elements'))->firstWhere('sujet_id', $c->id);
        $this->assertSame('organiser_livraison', $e['action']);
        $this->assertSame($this->transporteurB->transporteur->id, $e['transporteur_propose']['id']);

        // Commande à confirmer : bouton « Confirmer » directement dans la liste
        $nouvelle = $this->en($this->acheteur)->postJson('/api/commandes', ['stock_id' => $this->stock->id, 'quantite' => 1, 'moyen' => 'especes'])->json('commande.id');
        $e = collect($this->en($this->gestionnaire)->getJson('/api/a-faire')->json('elements'))->firstWhere('sujet_id', $nouvelle);
        $this->assertSame('confirmer', $e['action']);

        // Transporteur : accepter la mission d'un appui
        $e = collect($this->en($this->transporteurA)->getJson('/api/a-faire')->json('elements'))->firstWhere('categorie', 'livraison');
        $this->assertNotNull($e['action']);
    }
}

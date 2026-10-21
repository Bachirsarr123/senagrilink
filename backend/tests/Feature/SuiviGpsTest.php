<?php

namespace Tests\Feature;

use App\Models\Livraison;
use App\Models\PositionGps;
use App\Models\Reservation;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConstruitLivraison;
use Tests\TestCase;

/**
 * Lot 4 — GPS fiable : positions liées à la mission, envoyées par la personne
 * qui conduit (y compris par paquets après une coupure), visibles des seuls
 * acteurs de la mission pendant le trajet ; arrivée estimée ; purge à 30 jours.
 */
class SuiviGpsTest extends TestCase
{
    use ConstruitLivraison, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparerLivraison();
        // Entrepôt à Thiès, acheteur à Dakar
        $this->gestionnaire->entrepot->update(['latitude' => 14.79, 'longitude' => -16.93]);
        $this->acheteur->acheteurGros->update(['latitude' => 14.69, 'longitude' => -17.44]);
    }

    private function envoyer(Utilisateur $u, string $type, int $id, array $positions)
    {
        return $this->en($u)->postJson("/api/suivi/{$type}/{$id}/positions", ['positions' => $positions]);
    }

    private function voir(Utilisateur $u, string $type, int $id)
    {
        return $this->en($u)->getJson("/api/suivi/{$type}/{$id}");
    }

    public function test_livraison_positions_par_paquet_et_carte_des_acteurs(): void
    {
        $c   = $this->commandeJusqua('chargee');
        $lid = $this->livraisonId($c->id);

        // Avant le départ : pas de partage
        $this->envoyer($this->transporteurA, 'livraison', $lid, [['latitude' => 14.78, 'longitude' => -16.95]])
             ->assertStatus(422)->assertJsonPath('suivi_actif', false);
        $this->etapeLivraison($this->transporteurA, $c->id, 'en_cours')->assertOk();

        $this->envoyer($this->transporteurB, 'livraison', $lid, [['latitude' => 14.78, 'longitude' => -16.95]])->assertStatus(403);
        $this->envoyer($this->acheteur, 'livraison', $lid, [['latitude' => 14.78, 'longitude' => -16.95]])->assertStatus(403);

        // Paquet renvoyé après une coupure : heures du téléphone, une heure future ramenée à maintenant
        $this->envoyer($this->transporteurA, 'livraison', $lid, [
            ['latitude' => 14.78, 'longitude' => -16.97, 'horodatage' => now()->subSeconds(40)->toIso8601String(), 'vitesse' => 50],
            ['latitude' => 14.76, 'longitude' => -17.05, 'horodatage' => now()->subSeconds(20)->toIso8601String(), 'vitesse' => 60, 'precision' => 8],
            ['latitude' => 14.75, 'longitude' => -17.10, 'horodatage' => now()->addHour()->toIso8601String()],
        ])->assertStatus(201)->assertJsonPath('enregistrees', 3)->assertJsonPath('suivi_actif', true);
        // Même paquet renvoyé (réponse perdue) : rien n'est doublé
        $this->envoyer($this->transporteurA, 'livraison', $lid, [
            ['latitude' => 14.78, 'longitude' => -16.97, 'horodatage' => now()->subSeconds(40)->toIso8601String()],
        ])->assertOk()->assertJsonPath('enregistrees', 0);
        $this->assertSame(3, PositionGps::where('sujet_id', $lid)->count());

        $vue = $this->voir($this->acheteur, 'livraison', $lid)->assertOk()->json();
        $this->assertTrue($vue['en_route']);
        $this->assertTrue($vue['position_recente']);
        $this->assertSame([14.78, -16.97], $vue['trace'][0]);
        $this->assertSame(14.75, $vue['position']['latitude']);
        $this->assertSame([14.69, -17.44], [$vue['destination']['latitude'], $vue['destination']['longitude']]);
        $this->assertSame(trim($this->transporteurA->prenom.' '.$this->transporteurA->nom), $vue['conducteur']['nom']);
        // Estimation sans service de routage : distance × 1,3 à la vitesse récente
        $this->assertFalse($vue['estimation_routiere']);
        $this->assertGreaterThan(30, $vue['distance_restante_km']);
        $this->assertNotNull($vue['arrivee_estimee_le']);

        foreach ([$this->producteur, $this->gestionnaire, $this->transporteurA, Utilisateur::factory()->administrateur()->create()] as $u) {
            $this->voir($u, 'livraison', $lid)->assertOk();
        }
        foreach ([Utilisateur::factory()->acheteur()->create(), $this->transporteurB, Utilisateur::factory()->gestionnaire()->create(), Utilisateur::factory()->create()] as $u) {
            $this->voir($u, 'livraison', $lid)->assertStatus(403);
        }

        // Arrivée : les positions prises pendant le trajet restent acceptées, pas celles d'après
        $this->travelTo(now()->addMinutes(5));
        $this->etapeLivraison($this->transporteurA, $c->id, 'arrivee')->assertOk();
        $this->travelTo(now()->addMinutes(5));
        $this->envoyer($this->transporteurA, 'livraison', $lid, [
            ['latitude' => 14.72, 'longitude' => -17.30, 'horodatage' => now()->subMinutes(7)->toIso8601String()],
            ['latitude' => 14.69, 'longitude' => -17.44, 'horodatage' => now()->toIso8601String()],
        ])->assertStatus(201)->assertJsonPath('enregistrees', 1)->assertJsonPath('suivi_actif', false);

        // Q11 : plus de position visible après le trajet
        $fin = $this->voir($this->acheteur, 'livraison', $lid)->assertOk()->json();
        $this->assertFalse($fin['en_route']);
        $this->assertNull($fin['position']);
        $this->assertSame([], $fin['trace']);
    }

    public function test_position_ancienne_signalee_comme_derniere_position_connue(): void
    {
        $c   = $this->commandeJusqua('en_cours');
        $lid = $this->livraisonId($c->id);
        $this->envoyer($this->transporteurA, 'livraison', $lid, [['latitude' => 14.78, 'longitude' => -16.97]])->assertStatus(201);

        $this->travelTo(now()->addMinutes(3));
        $vue = $this->voir($this->acheteur, 'livraison', $lid)->json();
        $this->assertFalse($vue['position_recente']);
        $this->assertGreaterThanOrEqual(180, $vue['age_position_s']);
    }

    public function test_trajet_routier_avec_service_de_routage_et_repli(): void
    {
        config(['services.routage.url' => 'https://routage.test']);
        Http::fake(['routage.test/*' => Http::sequence()
            ->push(['routes' => [['distance' => 72000, 'duration' => 5400, 'geometry' => ['coordinates' => [[-16.97, 14.78], [-17.2, 14.7], [-17.44, 14.69]]]]]])
            ->push('erreur', 500)]);

        $c   = $this->commandeJusqua('en_cours');
        $lid = $this->livraisonId($c->id);
        $this->envoyer($this->transporteurA, 'livraison', $lid, [['latitude' => 14.78, 'longitude' => -16.97]])->assertStatus(201);

        $vue = $this->voir($this->acheteur, 'livraison', $lid)->json();
        $this->assertTrue($vue['estimation_routiere']);
        $this->assertEquals(72.0, $vue['distance_restante_km']);
        $this->assertSame([[14.78, -16.97], [14.7, -17.2], [14.69, -17.44]], $vue['trajet']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/route/v1/driving/-16.97,14.78;-17.44,14.69'));

        // Recalcul après 1 minute ; service en panne : estimation de repli, jamais d'erreur
        $this->travelTo(now()->addMinutes(3));
        $this->envoyer($this->transporteurA, 'livraison', $lid, [['latitude' => 14.75, 'longitude' => -17.1]])->assertStatus(201);
        $vue = $this->voir($this->acheteur, 'livraison', $lid)->json();
        $this->assertFalse($vue['estimation_routiere']);
        $this->assertNotNull($vue['arrivee_estimee_le']);
        // Le trajet n'apparaît pas dans les listes ordinaires
        $this->assertArrayNotHasKey('trajet', $this->vueTransporteur($this->transporteurA, $c->id));
    }

    public function test_acheminement_suivi_quand_le_producteur_livre_lui_meme(): void
    {
        $this->producteur->producteur->update(['latitude' => 14.5, 'longitude' => -16.5]);
        $rid = Reservation::create([
            'entrepot_id' => $this->gestionnaire->entrepot->id, 'producteur_id' => $this->producteur->producteur->id,
            'production_id' => $this->production($this->producteur)->id, 'produit' => 'riz', 'quantite_reservee' => 40,
            'date_debut' => '2026-06-02', 'date_reservation' => now(), 'statut' => 'confirmee', 'mode_transport' => 'producteur',
        ])->id;

        $this->envoyer($this->producteur, 'reservation', $rid, [['latitude' => 14.5, 'longitude' => -16.5]])->assertStatus(422);
        $this->en($this->producteur)->putJson("/api/reservations/{$rid}/etape", ['etape' => 'en_route'])->assertOk();
        $this->assertNotNull(Reservation::find($rid)->parti_le);
        $this->assertSame([['sujet_type' => 'reservation', 'sujet_id' => $rid, 'numero' => Reservation::find($rid)->numero_reservation]],
            $this->en($this->producteur)->getJson('/api/suivi/actifs')->json('suivis'));

        $this->envoyer($this->transporteurA, 'reservation', $rid, [['latitude' => 14.5, 'longitude' => -16.5]])->assertStatus(403);
        $this->envoyer($this->producteur, 'reservation', $rid, [['latitude' => 14.55, 'longitude' => -16.6, 'vitesse' => 45]])->assertStatus(201);

        $vue = $this->voir($this->gestionnaire, 'reservation', $rid)->assertOk()->json();
        $this->assertSame([14.79, -16.93], [$vue['destination']['latitude'], $vue['destination']['longitude']]);
        $this->assertSame([14.5, -16.5], [$vue['origine']['latitude'], $vue['origine']['longitude']]);
        $this->assertSame(14.55, $vue['position']['latitude']);
        $this->voir($this->acheteur, 'reservation', $rid)->assertStatus(403);
        $this->voir($this->transporteurA, 'reservation', $rid)->assertStatus(403);
    }

    public function test_missions_actives_du_transporteur_et_configuration_de_la_carte(): void
    {
        $c = $this->commandeJusqua('en_cours');
        $this->assertSame([$this->livraisonId($c->id)], collect($this->en($this->transporteurA)->getJson('/api/suivi/actifs')->json('suivis'))->pluck('sujet_id')->all());
        $this->assertSame([], $this->en($this->transporteurB)->getJson('/api/suivi/actifs')->json('suivis'));

        config(['services.carte.tuiles_url' => 'https://tuiles.exemple/{z}/{x}/{y}.png?key=publique']);
        $this->en($this->acheteur)->getJson('/api/suivi/configuration')->assertOk()
             ->assertJsonPath('tuiles_url', 'https://tuiles.exemple/{z}/{x}/{y}.png?key=publique')
             ->assertJsonPath('routage_routier', false);
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->getJson('/api/suivi/configuration')->assertStatus(401);
    }

    public function test_purge_des_positions_de_plus_de_30_jours(): void
    {
        $c   = $this->commandeJusqua('en_cours');
        $lid = $this->livraisonId($c->id);
        foreach ([31, 29, 0] as $jours) {
            PositionGps::create(['sujet_type' => 'livraison', 'sujet_id' => $lid, 'latitude' => 14.7, 'longitude' => -17.4, 'timestamp' => now()->subDays($jours)]);
        }

        Artisan::call('suivi:purger');
        $this->assertSame(2, PositionGps::count());
        $this->assertStringContainsString('1 position', Artisan::output());
    }
}

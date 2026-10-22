<?php

namespace Tests\Feature;

use App\Models\HistoriqueOperation;
use App\Models\Production;
use App\Models\Reservation;
use App\Models\Stock;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * Lot 2 — circuit « récolte → entrepôt » (cahier des charges v2, §4) :
 * mode de transport, acceptation de mission, étapes dans l'ordre, annulation
 * jusqu'au départ, réception contrôlée (code du lot, écart, refus).
 */
class CircuitDepotTest extends TestCase
{
    use ConstruitParcours, RefreshDatabase;

    private Utilisateur $producteur;
    private Utilisateur $gestionnaire;
    private Utilisateur $transporteurA;
    private Utilisateur $transporteurB;
    private Production $lot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->producteur    = Utilisateur::factory()->create();
        $this->gestionnaire  = Utilisateur::factory()->gestionnaire()->create();
        $this->transporteurA = Utilisateur::factory()->transporteur()->create();
        $this->transporteurB = Utilisateur::factory()->transporteur()->create();
        $this->lot           = $this->production($this->producteur); // 100 kg estimés
    }

    private function demander(float $quantite = 60, string $mode = 'transporteur')
    {
        return $this->en($this->producteur)->postJson('/api/reservations', [
            'entrepot_id' => $this->gestionnaire->entrepot->id, 'production_id' => $this->lot->id,
            'quantite_reservee' => $quantite, 'date_debut' => '2026-06-02', 'mode_transport' => $mode,
        ]);
    }

    private function confirmee(float $quantite = 60, string $mode = 'transporteur'): int
    {
        $rid = $this->demander($quantite, $mode)->assertStatus(201)->json('reservation.id');
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/confirmer")->assertOk();

        return $rid;
    }

    private function etape(Utilisateur $u, int $rid, string $etape)
    {
        return $this->en($u)->putJson("/api/reservations/{$rid}/etape", ['etape' => $etape]);
    }

    private function vue(Utilisateur $u, int $rid): array
    {
        return collect($this->en($u)->getJson('/api/reservations')->assertOk()->json('reservations'))->firstWhere('id', $rid);
    }

    private function notifications(Utilisateur $u): array
    {
        return $this->en($u)->getJson('/api/notifications')->json('notifications.*.data.type');
    }

    private function assigner(Utilisateur $transporteur, int $rid)
    {
        return $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/assigner-transporteur", ['transporteur_id' => $transporteur->transporteur->id]);
    }

    public function test_demande_le_mode_est_obligatoire_et_le_produit_repris_du_lot(): void
    {
        $this->en($this->producteur)->postJson('/api/reservations', [
            'entrepot_id' => $this->gestionnaire->entrepot->id, 'production_id' => $this->lot->id,
            'quantite_reservee' => 10, 'date_debut' => '2026-06-02',
        ])->assertStatus(422)->assertJsonValidationErrors('mode_transport');

        $this->demander(10)->assertStatus(201)->assertJsonPath('reservation.produit', 'riz')
             ->assertJsonPath('reservation.mode_transport', 'transporteur');
        $this->assertContains('reservation_demandee', $this->notifications($this->gestionnaire));
    }

    public function test_un_lot_ne_peut_pas_etre_depose_au_dela_de_sa_quantite(): void
    {
        $premiere = $this->confirmee(60);
        $this->demander(50)->assertStatus(422)->assertJsonPath('quantite_restante', 40);
        $this->demander(40)->assertStatus(201); // dépôt réparti sur deux demandes (Q8)

        // Une réception refusée rend la quantité au lot
        $this->assigner($this->transporteurA, $premiere)->assertOk();
        $this->acheminer($this->transporteurA, $premiere);
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$premiere}/refuser-reception", ['motif' => 'Humide'])->assertOk();
        $this->demander(60)->assertStatus(201);
    }

    public function test_mode_transporteur_parcours_complet_avec_refus_de_mission(): void
    {
        $rid = $this->confirmee();
        $this->assertSame(['remettre_en_attente', 'assigner'], $this->vue($this->gestionnaire, $rid)['actions']);
        $this->assertSame('Transporteur à affecter', $this->vue($this->producteur, $rid)['prochaine_etape']['libelle']);

        $this->assigner($this->transporteurA, $rid)->assertOk();
        $this->assertSame(['accepter_mission', 'refuser_mission'], $this->vue($this->transporteurA, $rid)['actions']);
        // Avant acceptation, aucune étape de transport
        $this->etape($this->transporteurA, $rid, 'au_point_collecte')->assertStatus(403);

        // Refus : motif obligatoire, la mission revient au gestionnaire
        $this->en($this->transporteurA)->putJson("/api/reservations/{$rid}/refuser-mission")->assertJsonValidationErrors('motif');
        $this->en($this->transporteurA)->putJson("/api/reservations/{$rid}/refuser-mission", ['motif' => 'Camion en panne'])->assertOk();
        $this->assertNull(Reservation::find($rid)->transporteur_id);
        $this->assertContains('mission_refusee', $this->notifications($this->gestionnaire));
        $this->en($this->transporteurA)->putJson("/api/reservations/{$rid}/accepter-mission")->assertStatus(403);

        $this->assigner($this->transporteurB, $rid)->assertOk();
        $this->en($this->transporteurB)->putJson("/api/reservations/{$rid}/accepter-mission")->assertOk()
             ->assertJsonPath('reservation.statut', 'prise_en_charge');
        $this->assertContains('mission_acceptee', $this->notifications($this->producteur));
        $this->en($this->transporteurB)->putJson("/api/reservations/{$rid}/accepter-mission")->assertStatus(422);

        // Les étapes se suivent dans l'ordre
        $this->etape($this->transporteurB, $rid, 'en_route')->assertStatus(422);
        $this->etape($this->transporteurB, $rid, 'au_point_collecte')->assertOk();
        $this->assertContains('transporteur_arrive', $this->notifications($this->producteur));
        $this->etape($this->transporteurB, $rid, 'chargee')->assertOk();
        // Le gestionnaire ne peut plus changer de transporteur une fois chez le producteur
        $this->assigner($this->transporteurA, $rid)->assertStatus(422);
        $this->etape($this->transporteurB, $rid, 'en_route')->assertOk();
        $this->assertContains('depot_en_route', $this->notifications($this->gestionnaire));
        // Après le départ, le producteur ne peut plus annuler
        $this->en($this->producteur)->putJson("/api/reservations/{$rid}/annuler")->assertStatus(422);
        $this->etape($this->transporteurB, $rid, 'arrivee_entrepot')->assertOk();
        $this->assertContains('arrivee_entrepot', $this->notifications($this->gestionnaire));
        $this->assertSame(['receptionner', 'refuser_reception'], $this->vue($this->gestionnaire, $rid)['actions']);

        $this->receptionner($this->gestionnaire, $rid, 60)->assertStatus(201);
        $this->assertSame(
            ['creation', 'confirmation', 'assignation_transporteur', 'refus_mission', 'assignation_transporteur', 'acceptation_mission',
             'au_point_collecte', 'chargee', 'en_route', 'arrivee_entrepot', 'reception'],
            HistoriqueOperation::where(['sujet_type' => 'reservation', 'sujet_id' => $rid])->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_mode_producteur_il_livre_lui_meme(): void
    {
        $rid = $this->confirmee(60, 'producteur');

        $this->assigner($this->transporteurA, $rid)->assertStatus(422);
        $this->assertSame(['partir', 'annuler'], $this->vue($this->producteur, $rid)['actions']);
        $this->assertSame('producteur', $this->vue($this->gestionnaire, $rid)['prochaine_etape']['acteur']);

        // Pas d'étapes de collecte : il part directement, puis arrive
        $this->etape($this->producteur, $rid, 'chargee')->assertStatus(422);
        $this->etape($this->transporteurA, $rid, 'en_route')->assertStatus(403);
        $this->etape($this->producteur, $rid, 'en_route')->assertOk();
        $this->assertSame(['arriver', 'declarer_incident'], $this->vue($this->producteur, $rid)['actions']);
        $this->etape($this->producteur, $rid, 'arrivee_entrepot')->assertOk();

        $this->receptionner($this->gestionnaire, $rid, 60)->assertStatus(201);
        $this->assertSame('enregistree', Reservation::find($rid)->statut);
        $this->assertSame(60.0, Stock::where('production_id', $this->lot->id)->sole()->quantite);
    }

    public function test_reception_controle_du_code_du_lot_et_de_lecart(): void
    {
        $rid = $this->confirmee(60, 'producteur');
        $this->etape($this->producteur, $rid, 'en_route')->assertOk();
        $this->etape($this->producteur, $rid, 'arrivee_entrepot')->assertOk();

        $this->receptionner($this->gestionnaire, $rid, 60, ['code_lot' => 'LOT-2026-FAUX00'])
             ->assertStatus(422)->assertJsonValidationErrors('code_lot');
        $this->receptionner($this->gestionnaire, $rid, 60, ['etat_marchandise' => 'pourri'])->assertJsonValidationErrors('etat_marchandise');
        $this->receptionner($this->gestionnaire, $rid, 55, ['motif' => ''])->assertStatus(422)->assertJsonValidationErrors('motif');
        $this->assertSame(0, Stock::count());

        // Code saisi en minuscules : accepté ; écart motivé : réception partielle
        $code = strtolower($this->lot->code_tracabilite);
        $this->receptionner($this->gestionnaire, $rid, 55, ['code_lot' => $code, 'etat_marchandise' => 'acceptable', 'motif' => 'Sacs percés'])
             ->assertStatus(201)->assertJsonPath('stock.quantite', 55);

        $r = Reservation::find($rid);
        $this->assertSame([55.0, 'acceptable'], [$r->quantite_recue, $r->etat_marchandise]);
        $this->assertEquals(55, $this->lot->fresh()->quantite_reelle);
        $this->assertSame('reception_partielle', HistoriqueOperation::where(['sujet_id' => $rid, 'sujet_type' => 'reservation'])->latest('id')->value('action'));
        $this->assertContains('reception_partielle', $this->notifications($this->producteur));
    }

    public function test_surplus_recu_limite_par_la_capacite(): void
    {
        $this->gestionnaire->entrepot->update(['capacite' => 70]);
        $rid = $this->confirmee(60, 'producteur');
        $this->etape($this->producteur, $rid, 'en_route')->assertOk();
        $this->etape($this->producteur, $rid, 'arrivee_entrepot')->assertOk();

        $this->receptionner($this->gestionnaire, $rid, 75)->assertStatus(422)->assertJsonPath('capacite_disponible', 10);
        $this->receptionner($this->gestionnaire, $rid, 70)->assertStatus(201);
    }

    public function test_reception_refusee(): void
    {
        $rid = $this->confirmee();
        $this->assigner($this->transporteurA, $rid)->assertOk();
        $this->acheminer($this->transporteurA, $rid);

        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/refuser-reception")->assertJsonValidationErrors('motif');
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/refuser-reception", ['motif' => 'Moisissures'])
             ->assertOk()->assertJsonPath('reservation.statut', 'reception_refusee');

        $this->assertSame(0, Stock::count());
        $this->assertSame('en_attente', $this->lot->fresh()->statut);
        $this->assertContains('reception_refusee', $this->notifications($this->producteur));
        $this->assertContains('reception_refusee', $this->notifications($this->transporteurA));
        $this->receptionner($this->gestionnaire, $rid, 60)->assertStatus(422);
    }

    public function test_annulation_jusquau_depart_previent_le_transporteur(): void
    {
        $rid = $this->confirmee();
        $this->assigner($this->transporteurA, $rid)->assertOk();
        $this->en($this->transporteurA)->putJson("/api/reservations/{$rid}/accepter-mission")->assertOk();
        $this->etape($this->transporteurA, $rid, 'au_point_collecte')->assertOk();
        $this->etape($this->transporteurA, $rid, 'chargee')->assertOk();

        $this->en($this->producteur)->putJson("/api/reservations/{$rid}/annuler", ['motif' => 'Acheteur direct'])->assertOk();
        $this->assertContains('mission_annulee', $this->notifications($this->transporteurA));
        $this->etape($this->transporteurA, $rid, 'en_route')->assertStatus(422);
    }

    public function test_transporteurs_les_moins_occupes_en_premier(): void
    {
        $rid = $this->confirmee(30);
        $this->assigner($this->transporteurA, $rid)->assertOk();

        $liste = $this->en($this->gestionnaire)->getJson('/api/transporteurs')->assertOk()->json('transporteurs');
        $this->assertSame([$this->transporteurB->transporteur->id, $this->transporteurA->transporteur->id], array_column($liste, 'id'));
        $this->assertSame([0, 1], array_column($liste, 'missions_actives'));
    }

    public function test_tracabilite_de_lacheminement(): void
    {
        $rid = $this->confirmee();
        $this->assigner($this->transporteurA, $rid)->assertOk();
        $this->acheminer($this->transporteurA, $rid);
        $this->receptionner($this->gestionnaire, $rid, 58)->assertStatus(201);

        $code = $this->lot->code_tracabilite;
        $etape = $this->en($this->producteur)->getJson("/api/tracabilite/{$code}")->assertOk()->json('tracabilite.etapes_acheminement.0');
        $this->assertSame([60.0, 58.0], [(float) $etape['quantite_prevue'], (float) $etape['quantite_recue']]);
        $this->assertSame(
            ['creation', 'confirmation', 'acceptation_mission', 'au_point_collecte', 'chargee', 'en_route', 'arrivee_entrepot', 'reception_partielle'],
            array_column($etape['etapes'], 'action'),
        );
        $this->assertNotNull($etape['transporteur']);

        // Un tiers voit les étapes datées, pas l'identité du transporteur
        $tiers = $this->en(Utilisateur::factory()->acheteur()->create())->getJson("/api/tracabilite/{$code}")->json('tracabilite.etapes_acheminement.0');
        $this->assertNull($tiers['transporteur']);
        $this->assertCount(8, $tiers['etapes']);
    }

    public function test_position_sur_la_carte_dans_le_profil(): void
    {
        $this->en($this->producteur)->putJson('/api/profile', ['latitude' => 14.7, 'longitude' => -17.4])->assertOk()
             ->assertJsonPath('utilisateur.producteur.latitude', 14.7);
        $acheteur = Utilisateur::factory()->acheteur()->create();
        $this->en($acheteur)->putJson('/api/profile', ['latitude' => 14.69, 'longitude' => -17.44])->assertOk()
             ->assertJsonPath('utilisateur.acheteur_gros.longitude', -17.44);
        $this->en($acheteur)->putJson('/api/profile', ['latitude' => 14.69])->assertJsonValidationErrors('longitude');

        // Le transporteur de la mission voit le téléphone, l'adresse et la position du producteur
        $rid = $this->confirmee();
        $this->assigner($this->transporteurA, $rid)->assertOk();
        $vue = $this->vue($this->transporteurA, $rid);
        $this->assertSame(14.7, $vue['producteur']['latitude']);
        $this->assertArrayHasKey('telephone', $vue['producteur']['utilisateur']);
        $this->assertSame($this->lot->code_tracabilite, $vue['production']['code_tracabilite']);
    }
}

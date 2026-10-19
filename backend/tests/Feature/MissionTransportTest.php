<?php

namespace Tests\Feature;

use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * Parcours récolte → réservation d'entrepôt → confirmation → assignation d'un
 * transporteur → mission visible chez lui (et seulement chez lui), puis
 * corrections : réassignation, retrait, refus, retour en attente, annulation.
 */
class MissionTransportTest extends TestCase
{
    use ConstruitParcours, RefreshDatabase;

    private Utilisateur $producteur;
    private Utilisateur $gestionnaire;
    private Utilisateur $transporteurA;
    private Utilisateur $transporteurB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->producteur    = Utilisateur::factory()->create();
        $this->gestionnaire  = Utilisateur::factory()->gestionnaire()->create();
        $this->transporteurA = Utilisateur::factory()->transporteur()->create();
        $this->transporteurB = Utilisateur::factory()->transporteur()->create();
    }

    /** Parcours réel par l'API jusqu'à une réservation confirmée ; renvoie son id. */
    private function reservationConfirmee(): int
    {
        $productionId = $this->en($this->producteur)->postJson('/api/productions', [
            'type_culture' => 'oignon', 'date_recolte' => '2026-06-01', 'quantite_estimee' => 50,
        ])->assertStatus(201)->json('production.id');

        $rid = $this->en($this->producteur)->postJson('/api/reservations', [
            'entrepot_id' => $this->gestionnaire->entrepot->id, 'production_id' => $productionId,
            'produit' => 'oignon', 'quantite_reservee' => 50, 'date_debut' => '2026-06-02',
        ])->assertStatus(201)->assertJsonPath('reservation.statut', 'en_attente')->json('reservation.id');

        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/confirmer")
             ->assertOk()->assertJsonPath('reservation.statut', 'confirmee');

        return $rid;
    }

    private function idTransporteur(Utilisateur $u): int
    {
        return $u->transporteur->id;
    }

    private function missions(Utilisateur $transporteur): array
    {
        return $this->en($transporteur)->getJson('/api/reservations')->assertOk()->json('reservations.*.id');
    }

    private function notifications(Utilisateur $u): array
    {
        return $this->en($u)->getJson('/api/notifications')->assertOk()->json('notifications.*.data.type');
    }

    public function test_la_mission_assignee_parvient_au_seul_transporteur_concerne(): void
    {
        $rid = $this->reservationConfirmee();

        $ids = $this->en($this->gestionnaire)->getJson('/api/transporteurs')->assertOk()->json('transporteurs.*.id');
        $this->assertContains($this->idTransporteur($this->transporteurA), $ids);

        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/assigner-transporteur", [
            'transporteur_id' => $this->idTransporteur($this->transporteurA),
        ])->assertOk()->assertJsonPath('reservation.transporteur_id', $this->idTransporteur($this->transporteurA));

        $this->assertDatabaseHas('reservations', ['id' => $rid, 'statut' => 'confirmee', 'transporteur_id' => $this->idTransporteur($this->transporteurA)]);
        $this->assertSame([$rid], $this->missions($this->transporteurA));
        $this->assertSame([], $this->missions($this->transporteurB));
        $this->assertContains('mission_assignee', $this->notifications($this->transporteurA));
        $this->assertNotContains('mission_assignee', $this->notifications($this->transporteurB));

        // Statut cohérent pour les trois rôles
        foreach ([$this->producteur, $this->gestionnaire, $this->transporteurA] as $u) {
            $this->en($u)->getJson('/api/reservations')
                 ->assertJsonPath('reservations.0.statut', 'confirmee')
                 ->assertJsonPath('reservations.0.transporteur_id', $this->idTransporteur($this->transporteurA));
        }

        // Le transporteur peut signaler l'arrivée ; l'autre non
        $this->en($this->transporteurB)->putJson("/api/reservations/{$rid}/arrivee")->assertStatus(403);
        $this->en($this->transporteurA)->putJson("/api/reservations/{$rid}/arrivee")->assertOk()
             ->assertJsonPath('reservation.statut', 'arrivee_entrepot');
        $this->en($this->producteur)->getJson('/api/reservations')->assertJsonPath('reservations.0.statut', 'arrivee_entrepot');
    }

    public function test_reassignation_retire_la_mission_a_lancien_et_la_donne_au_nouveau(): void
    {
        $rid = $this->reservationConfirmee();
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/assigner-transporteur", ['transporteur_id' => $this->idTransporteur($this->transporteurA)])->assertOk();

        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/assigner-transporteur", [
            'transporteur_id' => $this->idTransporteur($this->transporteurB), 'motif' => 'Mauvais transporteur choisi',
        ])->assertOk()->assertJsonPath('message', 'Mission réassignée au nouveau transporteur.');

        $this->assertSame([], $this->missions($this->transporteurA));
        $this->assertSame([$rid], $this->missions($this->transporteurB));
        $this->assertContains('mission_retiree', $this->notifications($this->transporteurA));
        $this->en($this->transporteurA)->putJson("/api/reservations/{$rid}/arrivee")->assertStatus(403);

        $this->assertDatabaseHas('historique_operations', [
            'sujet_type' => 'reservation', 'sujet_id' => $rid, 'action' => 'reassignation_transporteur',
            'motif' => 'Mauvais transporteur choisi', 'utilisateur_id' => $this->gestionnaire->id,
        ]);
        $this->en($this->gestionnaire)->getJson('/api/reservations')
             ->assertJsonPath('reservations.0.historique.1.action', 'reassignation_transporteur')
             ->assertJsonPath('reservations.0.historique.1.utilisateur.id', $this->gestionnaire->id);
        // Le transporteur ne reçoit pas l'historique (qui cite d'autres transporteurs)
        $this->en($this->transporteurB)->getJson('/api/reservations')->assertJsonMissingPath('reservations.0.historique');

        // Même transporteur : refus explicite, pas d'écriture
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/assigner-transporteur", ['transporteur_id' => $this->idTransporteur($this->transporteurB)])->assertStatus(422);
    }

    public function test_retrait_du_transporteur_puis_plus_de_correction_apres_arrivee(): void
    {
        $rid = $this->reservationConfirmee();
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/assigner-transporteur", ['transporteur_id' => $this->idTransporteur($this->transporteurA)])->assertOk();

        $this->en($this->gestionnaire)->deleteJson("/api/reservations/{$rid}/transporteur")->assertOk();
        $this->assertSame([], $this->missions($this->transporteurA));
        $this->en($this->gestionnaire)->deleteJson("/api/reservations/{$rid}/transporteur")->assertStatus(422); // double retrait

        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/assigner-transporteur", ['transporteur_id' => $this->idTransporteur($this->transporteurB)])->assertOk();
        $this->en($this->transporteurB)->putJson("/api/reservations/{$rid}/arrivee")->assertOk();

        // Trajet terminé : ni réassignation ni retrait
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/assigner-transporteur", ['transporteur_id' => $this->idTransporteur($this->transporteurA)])->assertStatus(422);
        $this->en($this->gestionnaire)->deleteJson("/api/reservations/{$rid}/transporteur")->assertStatus(422);
        $this->assertSame([$rid], $this->missions($this->transporteurB));
    }

    public function test_transporteur_bloque_non_assignable_et_absent_de_la_liste(): void
    {
        $rid = $this->reservationConfirmee();
        $this->transporteurB->forceFill(['statut' => 'bloque'])->save();

        $ids = $this->en($this->gestionnaire)->getJson('/api/transporteurs')->json('transporteurs.*.id');
        $this->assertNotContains($this->idTransporteur($this->transporteurB), $ids);
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/assigner-transporteur", ['transporteur_id' => $this->idTransporteur($this->transporteurB)])->assertStatus(422);
    }

    public function test_seul_le_gestionnaire_de_lentrepot_peut_assigner_ou_corriger(): void
    {
        $rid    = $this->reservationConfirmee();
        $autre  = Utilisateur::factory()->gestionnaire()->create();
        $corps  = ['transporteur_id' => $this->idTransporteur($this->transporteurA)];

        $this->en($autre)->putJson("/api/reservations/{$rid}/assigner-transporteur", $corps)->assertStatus(403);
        $this->en($this->transporteurA)->putJson("/api/reservations/{$rid}/assigner-transporteur", $corps)->assertStatus(403);
        $this->en($this->producteur)->putJson("/api/reservations/{$rid}/assigner-transporteur", $corps)->assertStatus(403);
        $this->en($autre)->putJson("/api/reservations/{$rid}/refuser", ['motif' => 'x'])->assertStatus(403);
        $this->en($autre)->deleteJson("/api/reservations/{$rid}/transporteur")->assertStatus(403);
    }

    public function test_refus_et_retour_en_attente_par_lentrepot(): void
    {
        $rid = $this->reservationConfirmee();

        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/refuser", ['motif' => 'x'])->assertStatus(422); // déjà confirmée
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/remettre-en-attente")->assertOk()
             ->assertJsonPath('reservation.statut', 'en_attente');
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/refuser")->assertStatus(422)->assertJsonValidationErrors('motif');
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/refuser", ['motif' => 'Entrepôt plein'])->assertOk()
             ->assertJsonPath('reservation.statut', 'refusee');
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/refuser", ['motif' => 'Entrepôt plein'])->assertStatus(422); // double refus

        $this->assertContains('reservation_refus', $this->notifications($this->producteur));
        $this->assertDatabaseHas('historique_operations', ['sujet_id' => $rid, 'action' => 'refus', 'ancien_statut' => 'en_attente', 'nouveau_statut' => 'refusee', 'motif' => 'Entrepôt plein']);
    }

    public function test_retour_en_attente_impossible_avec_un_transporteur_assigne(): void
    {
        $rid = $this->reservationConfirmee();
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/assigner-transporteur", ['transporteur_id' => $this->idTransporteur($this->transporteurA)])->assertOk();

        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/remettre-en-attente")->assertStatus(422);
    }

    public function test_annulation_par_le_producteur_selon_letat(): void
    {
        $rid = $this->reservationConfirmee();
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/assigner-transporteur", ['transporteur_id' => $this->idTransporteur($this->transporteurA)])->assertOk();

        $this->en($this->producteur)->putJson("/api/reservations/{$rid}/annuler")->assertStatus(422)
             ->assertJsonPath('statut_actuel', 'confirmee');
        $this->en(Utilisateur::factory()->create())->putJson("/api/reservations/{$rid}/annuler")->assertStatus(403);

        $this->en($this->gestionnaire)->deleteJson("/api/reservations/{$rid}/transporteur")->assertOk();
        $this->en($this->producteur)->putJson("/api/reservations/{$rid}/annuler", ['motif' => 'Erreur de quantité'])->assertOk()
             ->assertJsonPath('reservation.statut', 'annulee');
        $this->en($this->producteur)->putJson("/api/reservations/{$rid}/annuler")->assertStatus(422); // double annulation

        $this->assertContains('reservation_annulee', $this->notifications($this->gestionnaire));
        $this->en($this->gestionnaire)->putJson("/api/reservations/{$rid}/assigner-transporteur", ['transporteur_id' => $this->idTransporteur($this->transporteurA)])->assertStatus(422);
    }
}

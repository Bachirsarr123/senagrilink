<?php

namespace Tests\Feature;

use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * R2 — le producteur ne fixe pas le statut de ses productions : une récolte est
 * créée « en_attente » et devient « disponible » par la réception en entrepôt.
 */
class StatutProductionTest extends TestCase
{
    use ConstruitParcours, RefreshDatabase;

    private Utilisateur $producteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->producteur = Utilisateur::factory()->create();
    }

    private function recolte(array $extra = []): array
    {
        return array_merge(['type_culture' => 'riz', 'date_recolte' => '2026-06-01', 'quantite_estimee' => 100, 'prix_unitaire' => 200], $extra);
    }

    public function test_creation_sans_statut_donne_en_attente(): void
    {
        $this->en($this->producteur)
             ->postJson('/api/productions', $this->recolte())
             ->assertStatus(201)
             ->assertJsonPath('production.statut', 'en_attente');
    }

    public function test_creation_avec_un_statut_refusee(): void
    {
        foreach (['disponible', 'en_attente', 'n_importe_quoi'] as $statut) {
            $this->en($this->producteur)
                 ->postJson('/api/productions', $this->recolte(['statut' => $statut]))
                 ->assertStatus(422)
                 ->assertJsonValidationErrors('statut');
        }

        $this->assertDatabaseCount('productions', 0);
    }

    public function test_producteur_ne_peut_pas_se_declarer_disponible(): void
    {
        $production = $this->production($this->producteur);

        foreach (['disponible', 'inconnu'] as $statut) {
            $this->en($this->producteur)
                 ->putJson("/api/productions/{$production->id}", ['statut' => $statut])
                 ->assertStatus(422)
                 ->assertJsonValidationErrors('statut');
        }

        $this->assertSame('en_attente', $production->fresh()->statut);
    }

    public function test_producteur_modifie_toujours_les_autres_champs(): void
    {
        $production = $this->production($this->producteur);

        $this->en($this->producteur)
             ->putJson("/api/productions/{$production->id}", ['quantite_reelle' => 95, 'type_culture' => 'riz paddy'])
             ->assertOk()
             ->assertJsonPath('production.type_culture', 'riz paddy')
             ->assertJsonPath('production.statut', 'en_attente');
    }

    public function test_administrateur_ne_peut_utiliser_que_des_statuts_connus(): void
    {
        $production = $this->production($this->producteur);
        $admin      = Utilisateur::factory()->administrateur()->create();

        $this->en($admin)
             ->putJson("/api/productions/{$production->id}", ['statut' => 'inconnu'])
             ->assertStatus(422);

        $this->en($admin)
             ->putJson("/api/productions/{$production->id}", ['statut' => 'disponible'])
             ->assertOk()
             ->assertJsonPath('production.statut', 'disponible');
    }

    public function test_la_reception_validee_par_lentrepot_rend_le_lot_disponible(): void
    {
        $gestionnaire = Utilisateur::factory()->gestionnaire()->create();
        $transporteur = Utilisateur::factory()->transporteur()->create();
        $production   = $this->production($this->producteur);

        $rid = $this->en($this->producteur)->postJson('/api/reservations', [
            'entrepot_id' => $gestionnaire->entrepot->id, 'production_id' => $production->id,
            'produit' => 'riz', 'quantite_reservee' => 100, 'date_debut' => '2026-06-02', 'mode_transport' => 'transporteur',
        ])->assertStatus(201)->json('reservation.id');

        $this->en($gestionnaire)->putJson("/api/reservations/{$rid}/confirmer")->assertOk();
        $this->en($gestionnaire)->putJson("/api/reservations/{$rid}/assigner-transporteur", [
            'transporteur_id' => $transporteur->transporteur->id,
        ])->assertOk();
        $this->acheminer($transporteur, $rid);
        $this->receptionner($gestionnaire, $rid, 95)->assertStatus(201);

        $this->assertSame('disponible', $production->fresh()->statut);
    }
}

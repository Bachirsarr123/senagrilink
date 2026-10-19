<?php

namespace Tests\Feature;

use App\Models\Livraison;
use App\Models\PositionGps;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * R5 — la position du transporteur n'est exposée que pendant la livraison en cours.
 */
class PositionGpsConfidentialiteTest extends TestCase
{
    use ConstruitParcours, RefreshDatabase;

    private object $p;

    protected function setUp(): void
    {
        parent::setUp();

        $this->p = $this->parcours('en_cours');
        PositionGps::create([
            'transporteur_id' => $this->p->transporteur->transporteur->id,
            'latitude'        => 14.7,
            'longitude'       => -17.4,
            'timestamp'       => now(),
        ]);
    }

    private function position(Utilisateur $utilisateur, ?Livraison $livraison = null)
    {
        $livraison ??= $this->p->livraison;

        return $this->en($utilisateur)->getJson("/api/livraisons/{$livraison->id}/position");
    }

    public function test_pendant_la_livraison_les_acteurs_concernes_suivent_la_position(): void
    {
        $this->position($this->p->acheteur)->assertOk()->assertJsonPath('position.latitude', 14.7);
        $this->position($this->p->producteur)->assertOk();
    }

    public function test_apres_la_livraison_la_position_nest_plus_exposee(): void
    {
        $this->p->livraison->update(['statut' => 'livree']);

        $this->position($this->p->acheteur)
             ->assertStatus(404)
             ->assertJsonPath('statut_livraison', 'livree')
             ->assertJsonMissingPath('position');
        $this->position($this->p->producteur)->assertStatus(404)->assertJsonMissingPath('position');
    }

    public function test_avant_depart_ou_en_probleme_la_position_nest_pas_exposee(): void
    {
        foreach (['en_attente', 'probleme'] as $statut) {
            $this->p->livraison->update(['statut' => $statut]);

            $this->position($this->p->acheteur)->assertStatus(404)->assertJsonMissingPath('position');
        }
    }

    public function test_une_ancienne_livraison_ne_permet_pas_de_suivre_la_mission_suivante(): void
    {
        $this->p->livraison->update(['statut' => 'livree']);

        // Nouvelle mission du même transporteur pour un autre acheteur
        $autreAcheteur = Utilisateur::factory()->acheteur()->create();
        $nouvelle = Livraison::create([
            'commande_id'     => $this->commande($autreAcheteur, $this->p->stock, 'confirmee')->id,
            'transporteur_id' => $this->p->transporteur->transporteur->id,
            'origine'         => 'Entrepôt test',
            'destination'     => 'Thiès',
            'statut'          => 'en_cours',
        ]);

        $this->position($this->p->acheteur)->assertStatus(404);
        $this->position($autreAcheteur, $nouvelle)->assertOk();
    }

    public function test_un_tiers_reste_refuse_avant_et_apres_livraison(): void
    {
        $tiers = Utilisateur::factory()->acheteur()->create();

        $this->position($tiers)->assertStatus(403);
        $this->p->livraison->update(['statut' => 'livree']);
        $this->position($tiers)->assertStatus(403);
    }
}

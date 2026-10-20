<?php

namespace Tests\Feature;

use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-17 — le changement de mot de passe était ignoré (succès affiché, ancien
 * mot de passe toujours valide) ; l'adresse ne doit pas être modifiée quand
 * elle n'est pas envoyée.
 */
class ChangementMotDePasseTest extends TestCase
{
    use RefreshDatabase;

    private function connecter(string $motDePasse = 'ancien-mdp-1'): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/login', ['email' => 'u@test.sn', 'mot_de_passe' => $motDePasse])->assertOk()->json('access_token');
    }

    private function profil(string $jeton, array $corps)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($jeton)->putJson('/api/profile', $corps);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Utilisateur::factory()->create(['email' => 'u@test.sn', 'mot_de_passe' => 'ancien-mdp-1', 'adresse' => 'Thiès, quartier Nord']);
    }

    public function test_le_mot_de_passe_est_reellement_change_et_les_autres_sessions_revoquees(): void
    {
        $telephone  = $this->connecter();
        $ordinateur = $this->connecter();

        $this->profil($ordinateur, [
            'mot_de_passe_actuel' => 'ancien-mdp-1', 'mot_de_passe' => 'nouveau-mdp-2', 'mot_de_passe_confirmation' => 'nouveau-mdp-2',
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'u@test.sn', 'mot_de_passe' => 'ancien-mdp-1'])->assertStatus(401);
        $this->connecter('nouveau-mdp-2');

        $this->app['auth']->forgetGuards();
        $this->withToken($ordinateur)->getJson('/api/me')->assertOk();      // session courante conservée
        $this->app['auth']->forgetGuards();
        $this->withToken($telephone)->getJson('/api/me')->assertStatus(401); // autre session révoquée
    }

    public function test_mot_de_passe_actuel_exige_et_verifie(): void
    {
        $jeton = $this->connecter();

        $this->profil($jeton, ['mot_de_passe' => 'nouveau-mdp-2', 'mot_de_passe_confirmation' => 'nouveau-mdp-2'])
             ->assertStatus(422)->assertJsonValidationErrors('mot_de_passe_actuel');
        $this->profil($jeton, ['mot_de_passe_actuel' => 'faux', 'mot_de_passe' => 'nouveau-mdp-2', 'mot_de_passe_confirmation' => 'nouveau-mdp-2'])
             ->assertStatus(422)->assertJsonValidationErrors('mot_de_passe_actuel');
        $this->profil($jeton, ['mot_de_passe_actuel' => 'ancien-mdp-1', 'mot_de_passe' => 'court', 'mot_de_passe_confirmation' => 'court'])
             ->assertStatus(422)->assertJsonValidationErrors('mot_de_passe');
        $this->profil($jeton, ['mot_de_passe_actuel' => 'ancien-mdp-1', 'mot_de_passe' => 'nouveau-mdp-2', 'mot_de_passe_confirmation' => 'autre-mdp-3'])
             ->assertStatus(422)->assertJsonValidationErrors('mot_de_passe');

        $this->connecter('ancien-mdp-1'); // inchangé
    }

    public function test_profil_sans_mot_de_passe_ni_adresse_conserve_ladresse(): void
    {
        $jeton = $this->connecter();

        $this->profil($jeton, ['prenom' => 'Awa'])->assertOk()->assertJsonPath('utilisateur.adresse', 'Thiès, quartier Nord');
        $this->connecter('ancien-mdp-1');
    }

    public function test_changement_sans_jeton_personnel_ne_provoque_pas_d_erreur(): void
    {
        // Garde « web » (TransientToken), par exemple via actingAs : tous les jetons sont révoqués, sans erreur 500.
        $utilisateur = Utilisateur::where('email', 'u@test.sn')->first();
        $this->connecter();

        $this->actingAs($utilisateur, 'web')->putJson('/api/profile', [
            'mot_de_passe_actuel' => 'ancien-mdp-1', 'mot_de_passe' => 'nouveau-mdp-2', 'mot_de_passe_confirmation' => 'nouveau-mdp-2',
        ])->assertOk();

        $this->assertSame(0, $utilisateur->tokens()->count());
    }
}

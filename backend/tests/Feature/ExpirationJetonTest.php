<?php

namespace Tests\Feature;

use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * Persistance des sessions : un jeton reste valide jusqu'à la déconnexion
 * volontaire (aucune expiration par défaut, SANCTUM_EXPIRATION la réactive),
 * révocation à la déconnexion limitée à l'appareil courant, et codes de réponse
 * permettant aux clients de distinguer session invalide (401),
 * compte bloqué (403 + code) et simple refus de permission (403).
 */
class ExpirationJetonTest extends TestCase
{
    use ConstruitParcours, RefreshDatabase;

    private const SEPT_JOURS = 7 * 24 * 60;

    private function connexion(): string
    {
        Utilisateur::factory()->acheteur()->create(['email' => 'acheteur@test.sn', 'mot_de_passe' => 'password123']);

        return $this->postJson('/api/login', ['email' => 'acheteur@test.sn', 'mot_de_passe' => 'password123'])
                    ->assertOk()
                    ->json('access_token');
    }

    private function avecJeton(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function seConnecter(): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/login', ['email' => 'acheteur@test.sn', 'mot_de_passe' => 'password123'])
                    ->assertOk()
                    ->json('access_token');
    }

    public function test_aucune_expiration_par_defaut(): void
    {
        $this->assertNull(config('sanctum.expiration'));
    }

    public function test_la_connexion_nindique_pas_dexpiration_par_defaut(): void
    {
        Utilisateur::factory()->create(['email' => 'p@test.sn', 'mot_de_passe' => 'password123']);

        $this->postJson('/api/login', ['email' => 'p@test.sn', 'mot_de_passe' => 'password123'])
             ->assertOk()
             ->assertJsonPath('expires_at', null);
    }

    public function test_session_conservee_apres_longue_inactivite(): void
    {
        $token = $this->connexion();

        $this->travel(400)->days();
        $this->avecJeton($token)->getJson('/api/me')->assertOk();
        $this->avecJeton($token)->getJson('/api/catalogue')->assertOk();
    }

    public function test_jeton_conserve_entre_les_requetes_et_les_redemarrages(): void
    {
        // Le jeton est stocké (haché) en base : il ne dépend ni de la mémoire du
        // processus, ni du cache, ni de la session web, ni d'APP_KEY.
        $token = $this->connexion();
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->app['auth']->forgetGuards();
        $this->app['cache']->flush();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->avecJeton($token)->getJson('/api/me')->assertOk();
    }

    public function test_deconnexion_revoque_le_jeton_courant_uniquement(): void
    {
        $telephone  = $this->connexion();
        $ordinateur = $this->seConnecter();

        $this->avecJeton($telephone)->postJson('/api/logout')->assertOk();

        $this->avecJeton($telephone)->getJson('/api/me')->assertStatus(401);
        $this->avecJeton($telephone)->getJson('/api/catalogue')->assertStatus(401);
        $this->avecJeton($telephone)->postJson('/api/logout')->assertStatus(401);
        $this->avecJeton($ordinateur)->getJson('/api/me')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_acces_refuse_sans_authentification_valide(): void
    {
        $this->connexion();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me')->assertStatus(401);
        $this->avecJeton('inconnu')->getJson('/api/me')->assertStatus(401);
        $this->avecJeton('1|'.str_repeat('x', 40))->getJson('/api/catalogue')->assertStatus(401);
    }

    public function test_duree_maximale_reactivable_par_configuration(): void
    {
        config(['sanctum.expiration' => self::SEPT_JOURS]);
        $token = $this->connexion();

        $this->travel(self::SEPT_JOURS - 1)->minutes();
        $this->avecJeton($token)->getJson('/api/me')->assertOk();

        $this->travel(2)->minutes();
        $this->avecJeton($token)->getJson('/api/me')->assertStatus(401);

        $nouveau = $this->seConnecter();
        $this->avecJeton($nouveau)->getJson('/api/me')->assertOk();
    }

    public function test_la_connexion_indique_lexpiration_si_configuree(): void
    {
        config(['sanctum.expiration' => self::SEPT_JOURS]);
        $this->freezeSecond();
        Utilisateur::factory()->create(['email' => 'p@test.sn', 'mot_de_passe' => 'password123']);

        $this->postJson('/api/login', ['email' => 'p@test.sn', 'mot_de_passe' => 'password123'])
             ->assertOk()
             ->assertJsonPath('expires_at', now()->addMinutes(self::SEPT_JOURS)->toIso8601String());
    }

    public function test_compte_bloque_identifie_par_un_code_explicite(): void
    {
        $acheteur = Utilisateur::factory()->acheteur()->create(['email' => 'b@test.sn', 'mot_de_passe' => 'password123']);
        $token    = $acheteur->createToken('ancien')->plainTextToken;
        $acheteur->forceFill(['statut' => 'bloque'])->save();

        $this->avecJeton($token)->getJson('/api/me')
             ->assertStatus(403)
             ->assertJsonPath('code', 'compte_bloque');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'b@test.sn', 'mot_de_passe' => 'password123'])
             ->assertStatus(403)
             ->assertJsonPath('code', 'compte_bloque');
    }

    public function test_refus_de_permission_sans_code_compte_bloque(): void
    {
        $producteur = Utilisateur::factory()->create();

        $this->en($producteur)->getJson('/api/stocks')
             ->assertStatus(403)
             ->assertJsonMissingPath('code');
    }
}

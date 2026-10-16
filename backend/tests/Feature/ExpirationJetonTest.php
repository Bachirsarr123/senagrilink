<?php

namespace Tests\Feature;

use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * R4 — expiration des jetons Sanctum (SANCTUM_EXPIRATION, défaut 7 jours) et
 * codes de réponse permettant aux clients de distinguer session invalide (401),
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

    public function test_duree_par_defaut_sept_jours(): void
    {
        $this->assertSame(self::SEPT_JOURS, config('sanctum.expiration'));
    }

    public function test_la_connexion_indique_lexpiration_du_jeton(): void
    {
        $this->freezeSecond();
        Utilisateur::factory()->create(['email' => 'p@test.sn', 'mot_de_passe' => 'password123']);

        $this->postJson('/api/login', ['email' => 'p@test.sn', 'mot_de_passe' => 'password123'])
             ->assertOk()
             ->assertJsonPath('expires_at', now()->addMinutes(self::SEPT_JOURS)->toIso8601String());
    }

    public function test_jeton_valide_avant_expiration_puis_refuse_apres(): void
    {
        $token = $this->connexion();

        $this->travel(self::SEPT_JOURS - 1)->minutes();
        $this->avecJeton($token)->getJson('/api/me')->assertOk();

        $this->travel(2)->minutes();
        $this->avecJeton($token)->getJson('/api/me')->assertStatus(401);
        $this->avecJeton($token)->getJson('/api/catalogue')->assertStatus(401);
    }

    public function test_reconnexion_possible_apres_expiration(): void
    {
        $token = $this->connexion();
        $this->travel(self::SEPT_JOURS + 1)->minutes();
        $this->avecJeton($token)->getJson('/api/me')->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $nouveau = $this->postJson('/api/login', ['email' => 'acheteur@test.sn', 'mot_de_passe' => 'password123'])
                        ->assertOk()->json('access_token');

        $this->avecJeton($nouveau)->getJson('/api/me')->assertOk();
    }

    public function test_expiration_desactivable_par_configuration(): void
    {
        config(['sanctum.expiration' => null]);
        $token = $this->connexion();

        $this->travel(365)->days();
        $this->avecJeton($token)->getJson('/api/me')->assertOk();
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

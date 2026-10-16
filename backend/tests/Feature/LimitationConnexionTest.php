<?php

namespace Tests\Feature;

use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R3 — limitation des tentatives de connexion : 5 par minute pour un même
 * e-mail et une même IP, 20 par minute pour une IP, puis HTTP 429.
 */
class LimitationConnexionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Utilisateur::factory()->create([
            'email'        => 'producteur@test.sn',
            'mot_de_passe' => 'password123',
        ]);
    }

    private function connexion(string $email, string $motDePasse)
    {
        return $this->postJson('/api/login', ['email' => $email, 'mot_de_passe' => $motDePasse]);
    }

    public function test_connexion_valide_dans_les_conditions_normales(): void
    {
        $this->connexion('producteur@test.sn', 'password123')
             ->assertOk()
             ->assertJsonStructure(['access_token']);
    }

    public function test_tentatives_incorrectes_puis_limite_atteinte(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->connexion('producteur@test.sn', 'mauvais')->assertStatus(401);
        }

        $this->connexion('producteur@test.sn', 'mauvais')
             ->assertStatus(429)
             ->assertJsonPath('message', 'Trop de tentatives de connexion. Réessayez dans une minute.');

        // Pendant la fenêtre, même le bon mot de passe est refusé
        $this->connexion('producteur@test.sn', 'password123')->assertStatus(429);
    }

    public function test_meme_comportement_pour_un_compte_inexistant(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->connexion('inconnu@test.sn', 'mauvais')->assertStatus(401);
        }

        $this->connexion('inconnu@test.sn', 'mauvais')->assertStatus(429);
    }

    public function test_limite_propre_a_lemail_naffecte_pas_les_autres_comptes(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->connexion('inconnu@test.sn', 'mauvais');
        }

        $this->connexion('producteur@test.sn', 'password123')->assertOk();
    }

    public function test_limite_par_ip_sur_des_emails_differents(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->connexion("essai{$i}@test.sn", 'mauvais')->assertStatus(401);
        }

        $this->connexion('essai-suivant@test.sn', 'mauvais')->assertStatus(429);
    }

    public function test_compteur_reinitialise_apres_le_delai(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->connexion('producteur@test.sn', 'mauvais');
        }
        $this->connexion('producteur@test.sn', 'password123')->assertStatus(429);

        $this->travel(61)->seconds();

        $this->connexion('producteur@test.sn', 'mauvais')->assertStatus(401);
        $this->connexion('producteur@test.sn', 'password123')->assertOk();
    }

    public function test_inscription_non_limitee_par_ce_limiteur(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->postJson('/api/register', [
                'nom'                       => 'Test',
                'prenom'                    => 'Acheteur',
                'email'                     => "acheteur{$i}@test.sn",
                'mot_de_passe'              => 'password123',
                'mot_de_passe_confirmation' => 'password123',
                'role'                      => 'acheteur_gros',
                'type_activite'             => 'grossiste',
                'volume_achat_mensuel'      => 100,
            ])->assertStatus(201);
        }
    }
}

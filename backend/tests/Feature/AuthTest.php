<?php

namespace Tests\Feature;

use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    // ── Inscription ───────────────────────────────────────────────────────────

    public function test_inscription_producteur_reussie(): void
    {
        $response = $this->postJson('/api/register', [
            'nom'                      => 'Diallo',
            'prenom'                   => 'Mamadou',
            'email'                    => 'mamadou@test.sn',
            'mot_de_passe'             => 'password123',
            'mot_de_passe_confirmation'=> 'password123',
            'role'                     => 'producteur',
            'region'                   => 'Thiès',
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'message',
                     'utilisateur' => ['id', 'nom', 'email', 'role'],
                     'access_token',
                     'token_type',
                 ]);

        $this->assertDatabaseHas('utilisateurs', ['email' => 'mamadou@test.sn']);
        $this->assertDatabaseHas('producteurs', ['region' => 'Thiès']);
    }

    public function test_inscription_email_duplique_retourne_422(): void
    {
        Utilisateur::factory()->create(['email' => 'double@test.sn']);

        $response = $this->postJson('/api/register', [
            'nom'                      => 'Sarr',
            'prenom'                   => 'Bachir',
            'email'                    => 'double@test.sn',
            'mot_de_passe'             => 'password123',
            'mot_de_passe_confirmation'=> 'password123',
            'role'                     => 'producteur',
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('errors.email.0', 'Cette adresse email est déjà utilisée.');
    }

    public function test_inscription_role_administrateur_refuse(): void
    {
        $response = $this->postJson('/api/register', [
            'nom'                      => 'Test',
            'prenom'                   => 'Admin',
            'email'                    => 'admin@test.sn',
            'mot_de_passe'             => 'password123',
            'mot_de_passe_confirmation'=> 'password123',
            'role'                     => 'administrateur',   // non autorisé en auto-inscription
        ]);

        $response->assertStatus(422);
    }

    // ── Connexion ─────────────────────────────────────────────────────────────

    public function test_connexion_reussie(): void
    {
        Utilisateur::factory()->create([
            'email'        => 'login@test.sn',
            'mot_de_passe' => 'password123',
            'role'         => 'producteur',
        ]);

        $response = $this->postJson('/api/login', [
            'email'        => 'login@test.sn',
            'mot_de_passe' => 'password123',
        ]);

        $response->assertOk()
                 ->assertJsonStructure(['access_token', 'token_type', 'utilisateur']);
    }

    public function test_connexion_identifiants_incorrects(): void
    {
        Utilisateur::factory()->create(['email' => 'user@test.sn']);

        $response = $this->postJson('/api/login', [
            'email'        => 'user@test.sn',
            'mot_de_passe' => 'mauvais_mdp',
        ]);

        $response->assertStatus(401)
                 ->assertJsonPath('message', 'Identifiants incorrects.');
    }

    public function test_connexion_compte_bloque_retourne_403(): void
    {
        Utilisateur::factory()->bloque()->create([
            'email'        => 'bloque@test.sn',
            'mot_de_passe' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'email'        => 'bloque@test.sn',
            'mot_de_passe' => 'password123',
        ]);

        $response->assertStatus(403);
    }

    // ── Profil & Déconnexion ──────────────────────────────────────────────────

    public function test_me_retourne_utilisateur_connecte(): void
    {
        $utilisateur = Utilisateur::factory()->create();
        $token = $utilisateur->createToken('test')->plainTextToken;

        $this->withToken($token)
             ->getJson('/api/me')
             ->assertOk()
             ->assertJsonPath('utilisateur.email', $utilisateur->email);
    }

    public function test_me_sans_token_retourne_401(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_deconnexion_reussie(): void
    {
        $utilisateur = Utilisateur::factory()->create();
        $token = $utilisateur->createToken('test')->plainTextToken;

        $this->withToken($token)
             ->postJson('/api/logout')
             ->assertOk()
             ->assertJsonPath('message', 'Déconnexion réussie.');

        // Le token doit être révoqué
        $this->withToken($token)
             ->getJson('/api/me')
             ->assertStatus(401);
    }
}

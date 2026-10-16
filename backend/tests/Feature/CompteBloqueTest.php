<?php

namespace Tests\Feature;

use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

/**
 * V1 — un compte bloqué ne doit plus pouvoir utiliser l'API, y compris avec
 * des jetons Sanctum délivrés avant le blocage.
 */
class CompteBloqueTest extends TestCase
{
    use RefreshDatabase;

    private string $tokenAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Utilisateur::factory()->administrateur()->create();
        $this->tokenAdmin = $admin->createToken('admin')->plainTextToken;
    }

    /**
     * Requête avec un jeton donné. Le garde Sanctum met l'utilisateur en cache
     * pendant un test : on le réinitialise pour simuler une requête HTTP neuve.
     */
    private function avecJeton(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /**
     * Diffuseur Reverb avec identifiants fictifs : la signature d'un canal privé
     * est calculée localement (aucune connexion réseau), ce qui permet de tester
     * l'autorisation réelle des canaux de routes/channels.php.
     */
    private function activerReverbFictif(): void
    {
        config([
            'broadcasting.default'                       => 'reverb',
            'broadcasting.connections.reverb.key'        => 'cle-fictive',
            'broadcasting.connections.reverb.secret'     => 'secret-fictif',
            'broadcasting.connections.reverb.app_id'     => 'id-fictif',
            'broadcasting.connections.reverb.options.host'   => '127.0.0.1',
            'broadcasting.connections.reverb.options.port'   => 9,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        Broadcast::purge('reverb');
        require base_path('routes/channels.php'); // canaux déclarés sur le nouveau diffuseur
    }

    private function authCanal(string $token, int $utilisateurId)
    {
        return $this->avecJeton($token)->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-utilisateur.{$utilisateurId}",
            'socket_id'    => '1234.5678',
        ]);
    }

    private function bloquer(Utilisateur $utilisateur): void
    {
        $this->avecJeton($this->tokenAdmin)
             ->putJson("/api/admin/utilisateurs/{$utilisateur->id}/bloquer")
             ->assertOk()
             ->assertJsonPath('utilisateur.statut', 'bloque');
    }

    public function test_compte_actif_conserve_son_acces(): void
    {
        $acheteur = Utilisateur::factory()->acheteur()->create();
        $token    = $acheteur->createToken('web')->plainTextToken;

        $this->avecJeton($token)->getJson('/api/me')->assertOk();
        $this->avecJeton($token)->getJson('/api/catalogue')->assertOk();
        $this->avecJeton($token)->putJson('/api/profile', ['nom' => 'Ndiaye'])->assertOk();

        // Autorisation réelle du canal privé (et non plus le pilote null, qui accepte tout)
        $this->activerReverbFictif();
        $signature = $this->authCanal($token, $acheteur->id)->assertOk()->json('auth');
        $this->assertStringStartsWith('cle-fictive:', $signature);
        $this->authCanal($token, $this->admin()->id)->assertStatus(403); // canal d'autrui
    }

    public function test_diffusion_reelle_refusee_a_un_compte_bloque(): void
    {
        $acheteur = Utilisateur::factory()->acheteur()->create();
        $token    = $acheteur->createToken('ancien')->plainTextToken;
        $acheteur->forceFill(['statut' => 'bloque'])->save();

        $this->activerReverbFictif();
        $this->authCanal($token, $acheteur->id)
             ->assertStatus(403)
             ->assertJsonMissingPath('auth');
    }

    private function admin(): Utilisateur
    {
        return Utilisateur::where('role', 'administrateur')->first();
    }

    public function test_blocage_revoque_tous_les_jetons_du_compte(): void
    {
        $acheteur  = Utilisateur::factory()->acheteur()->create();
        $tokenWeb  = $acheteur->createToken('web')->plainTextToken;
        $tokenMobi = $acheteur->createToken('mobile')->plainTextToken;
        $this->assertSame(2, $acheteur->tokens()->count());

        $this->bloquer($acheteur);

        $this->assertSame(0, $acheteur->tokens()->count());
        $this->avecJeton($tokenWeb)->getJson('/api/me')->assertStatus(401);
        $this->avecJeton($tokenMobi)->getJson('/api/catalogue')->assertStatus(401);
        $this->avecJeton($tokenMobi)->putJson('/api/profile', ['nom' => 'X'])->assertStatus(401);
    }

    public function test_blocage_ne_revoque_pas_les_jetons_des_autres_comptes(): void
    {
        $bloque = Utilisateur::factory()->acheteur()->create();
        $autre  = Utilisateur::factory()->acheteur()->create();
        $bloque->createToken('web');
        $tokenAutre = $autre->createToken('web')->plainTextToken;

        $this->bloquer($bloque);

        $this->assertSame(1, $autre->tokens()->count());
        $this->avecJeton($tokenAutre)->getJson('/api/me')->assertOk();
        $this->avecJeton($this->tokenAdmin)->getJson('/api/admin/dashboard')->assertOk();
    }

    /**
     * Cas d'un jeton encore présent alors que le compte est bloqué (jeton émis
     * avant ce correctif, statut modifié directement en base...) : le middleware
     * doit refuser en lecture comme en écriture.
     */
    public function test_jeton_restant_dun_compte_bloque_est_refuse(): void
    {
        $producteur = Utilisateur::factory()->create();
        $token      = $producteur->createToken('ancien')->plainTextToken;
        $producteur->forceFill(['statut' => 'bloque'])->save();

        $this->avecJeton($token)->getJson('/api/me')->assertStatus(403);
        $this->avecJeton($token)->getJson('/api/productions')->assertStatus(403);
        $this->avecJeton($token)->postJson('/api/productions', [
            'type_culture'     => 'riz',
            'date_recolte'     => '2026-06-01',
            'quantite_estimee' => 100,
        ])->assertStatus(403);
        $this->avecJeton($token)->putJson('/api/profile', ['nom' => 'X'])->assertStatus(403);
        $this->avecJeton($token)->getJson('/api/notifications')->assertStatus(403);
        $this->avecJeton($token)->postJson('/api/broadcasting/auth')->assertStatus(403);

        $this->assertDatabaseCount('productions', 0);
        $this->assertDatabaseMissing('utilisateurs', ['id' => $producteur->id, 'nom' => 'X']);
    }

    public function test_compte_bloque_ne_peut_pas_se_reconnecter(): void
    {
        $acheteur = Utilisateur::factory()->acheteur()->create([
            'email'        => 'bloque@test.sn',
            'mot_de_passe' => 'password123',
        ]);

        $this->bloquer($acheteur);

        $this->postJson('/api/login', ['email' => 'bloque@test.sn', 'mot_de_passe' => 'password123'])
             ->assertStatus(403);
        $this->assertSame(0, $acheteur->tokens()->count());
    }

    public function test_compte_debloque_se_reconnecte_avec_un_nouveau_jeton(): void
    {
        $acheteur = Utilisateur::factory()->acheteur()->create([
            'email'        => 'retour@test.sn',
            'mot_de_passe' => 'password123',
        ]);
        $ancienToken = $acheteur->createToken('web')->plainTextToken;

        $this->bloquer($acheteur);

        $this->avecJeton($this->tokenAdmin)
             ->putJson("/api/admin/utilisateurs/{$acheteur->id}/debloquer")
             ->assertOk()
             ->assertJsonPath('utilisateur.statut', 'actif');

        // L'ancien jeton a été révoqué au blocage : il ne redevient pas valide.
        $this->avecJeton($ancienToken)->getJson('/api/me')->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $nouveauToken = $this->postJson('/api/login', [
            'email'        => 'retour@test.sn',
            'mot_de_passe' => 'password123',
        ])->assertOk()->json('access_token');

        $this->assertNotEmpty($nouveauToken);
        $this->avecJeton($nouveauToken)->getJson('/api/me')->assertOk();
        $this->avecJeton($nouveauToken)->getJson('/api/catalogue')->assertOk();
    }

    public function test_admin_administre_toujours_les_autres_comptes(): void
    {
        $transporteur = Utilisateur::factory()->transporteur()->create();

        $this->bloquer($transporteur);

        $this->avecJeton($this->tokenAdmin)
             ->getJson('/api/admin/utilisateurs?statut=bloque')
             ->assertOk()
             ->assertJsonPath('total', 1);

        // Règles existantes inchangées
        $this->avecJeton($this->tokenAdmin)
             ->putJson("/api/admin/utilisateurs/{$transporteur->id}/bloquer")
             ->assertStatus(422);

        $this->avecJeton($this->tokenAdmin)
             ->putJson("/api/admin/utilisateurs/{$transporteur->id}/debloquer")
             ->assertOk();

        $this->avecJeton($this->tokenAdmin)
             ->putJson("/api/admin/utilisateurs/{$transporteur->id}/debloquer")
             ->assertStatus(422);
    }

    public function test_admin_ne_peut_pas_se_bloquer_lui_meme(): void
    {
        $admin = Utilisateur::where('role', 'administrateur')->first();

        $this->avecJeton($this->tokenAdmin)
             ->putJson("/api/admin/utilisateurs/{$admin->id}/bloquer")
             ->assertStatus(422);

        $this->assertSame('actif', $admin->fresh()->statut);
        $this->avecJeton($this->tokenAdmin)->getJson('/api/admin/dashboard')->assertOk();
    }

    public function test_non_administrateur_ne_peut_pas_bloquer(): void
    {
        $gestionnaire = Utilisateur::factory()->gestionnaire()->create();
        $cible        = Utilisateur::factory()->acheteur()->create();
        $cible->createToken('web');

        $this->avecJeton($gestionnaire->createToken('web')->plainTextToken)
             ->putJson("/api/admin/utilisateurs/{$cible->id}/bloquer")
             ->assertStatus(403);

        $this->assertSame('actif', $cible->fresh()->statut);
        $this->assertSame(1, $cible->tokens()->count());
    }
}

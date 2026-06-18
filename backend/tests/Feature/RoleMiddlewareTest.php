<?php

namespace Tests\Feature;

use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private function tokenPour(string $role): string
    {
        $utilisateur = Utilisateur::factory()->create(['role' => $role]);
        return $utilisateur->createToken('test')->plainTextToken;
    }

    // ── Producteur ────────────────────────────────────────────────────────────

    public function test_producteur_peut_acceder_productions(): void
    {
        $this->withToken($this->tokenPour('producteur'))
             ->getJson('/api/productions')
             ->assertOk();
    }

    public function test_producteur_ne_peut_pas_acceder_stocks(): void
    {
        $this->withToken($this->tokenPour('producteur'))
             ->getJson('/api/stocks')
             ->assertStatus(403);
    }

    public function test_producteur_ne_peut_pas_acceder_admin(): void
    {
        $this->withToken($this->tokenPour('producteur'))
             ->getJson('/api/admin/utilisateurs')
             ->assertStatus(403);
    }

    // ── Gestionnaire d'entrepôt ───────────────────────────────────────────────

    public function test_gestionnaire_peut_acceder_stocks(): void
    {
        $this->withToken($this->tokenPour('gestionnaire_entrepot'))
             ->getJson('/api/stocks')
             ->assertOk();
    }

    public function test_gestionnaire_ne_peut_pas_acceder_productions(): void
    {
        $this->withToken($this->tokenPour('gestionnaire_entrepot'))
             ->getJson('/api/productions')
             ->assertStatus(403);
    }

    // ── Acheteur en gros ─────────────────────────────────────────────────────

    public function test_acheteur_peut_acceder_catalogue(): void
    {
        $this->withToken($this->tokenPour('acheteur_gros'))
             ->getJson('/api/catalogue')
             ->assertOk();
    }

    public function test_acheteur_ne_peut_pas_acceder_stocks(): void
    {
        $this->withToken($this->tokenPour('acheteur_gros'))
             ->getJson('/api/stocks')
             ->assertStatus(403);
    }

    // ── Transporteur ─────────────────────────────────────────────────────────

    public function test_transporteur_peut_acceder_livraisons(): void
    {
        $this->withToken($this->tokenPour('transporteur'))
             ->getJson('/api/livraisons')
             ->assertOk();
    }

    public function test_transporteur_ne_peut_pas_acceder_admin(): void
    {
        $this->withToken($this->tokenPour('transporteur'))
             ->getJson('/api/admin/dashboard')
             ->assertStatus(403);
    }

    // ── Administrateur ────────────────────────────────────────────────────────

    public function test_admin_peut_acceder_dashboard(): void
    {
        $this->withToken($this->tokenPour('administrateur'))
             ->getJson('/api/admin/dashboard')
             ->assertOk();
    }

    public function test_admin_peut_acceder_stocks_via_bypass_role(): void
    {
        // L'admin bypass tous les guards de rôle (CheckRole)
        $this->withToken($this->tokenPour('administrateur'))
             ->getJson('/api/stocks')
             ->assertOk();
    }

    public function test_admin_peut_acceder_productions_via_bypass_role(): void
    {
        $this->withToken($this->tokenPour('administrateur'))
             ->getJson('/api/productions')
             ->assertOk();
    }

    // ── Non authentifié ───────────────────────────────────────────────────────

    public function test_requete_sans_token_retourne_401(): void
    {
        $this->getJson('/api/productions')->assertStatus(401);
        $this->getJson('/api/stocks')->assertStatus(401);
        $this->getJson('/api/livraisons')->assertStatus(401);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Utilisateur;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * R8 — la plateforme garde toujours au moins un administrateur actif.
 *
 * Séquentiellement, le dernier administrateur ne peut pas être bloqué (l'auto-
 * blocage est déjà refusé) ; le risque réel est le blocage croisé simultané.
 * Le test ci-dessous simule la requête concurrente qui aboutit juste avant la
 * transaction ; le verrouillage lui-même est vérifié sur PostgreSQL
 * (ConcurrencePostgresTest).
 */
class DernierAdministrateurTest extends TestCase
{
    use ConstruitParcours, RefreshDatabase;

    private Utilisateur $admin1;
    private Utilisateur $admin2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin1 = Utilisateur::factory()->administrateur()->create();
        $this->admin2 = Utilisateur::factory()->administrateur()->create();
    }

    public function test_un_administrateur_peut_bloquer_un_autre_administrateur(): void
    {
        $this->en($this->admin1)
             ->putJson("/api/admin/utilisateurs/{$this->admin2->id}/bloquer")
             ->assertOk();

        $this->assertSame('bloque', $this->admin2->fresh()->statut);
        $this->assertSame('actif', $this->admin1->fresh()->statut);
    }

    public function test_blocage_croise_ne_laisse_pas_la_plateforme_sans_administrateur(): void
    {
        $this->admin2->createToken('mobile');

        // L'admin 2 vient de bloquer l'admin 1 pendant que la requête de l'admin 1
        // (déjà authentifiée) bloque l'admin 2.
        $fait = false;
        Event::listen(TransactionBeginning::class, function () use (&$fait) {
            if (! $fait) {
                $fait = true;
                DB::table('utilisateurs')->where('id', $this->admin1->id)->update(['statut' => 'bloque']);
            }
        });

        $this->en($this->admin1)
             ->putJson("/api/admin/utilisateurs/{$this->admin2->id}/bloquer")
             ->assertStatus(422)
             ->assertJsonPath('message', 'Impossible de bloquer le dernier administrateur actif.');

        $this->assertSame('actif', $this->admin2->fresh()->statut);
        $this->assertSame(1, $this->admin2->tokens()->where('name', 'mobile')->count()); // jetons non révoqués
    }

    public function test_les_autres_comptes_restent_administrables_avec_un_seul_administrateur(): void
    {
        $this->admin2->forceFill(['statut' => 'bloque'])->save();
        $acheteur = Utilisateur::factory()->acheteur()->create();

        $this->en($this->admin1)
             ->putJson("/api/admin/utilisateurs/{$acheteur->id}/bloquer")
             ->assertOk();

        $this->en($this->admin1)
             ->putJson("/api/admin/utilisateurs/{$acheteur->id}/debloquer")
             ->assertOk();
    }

    public function test_regles_existantes_inchangees(): void
    {
        $this->en($this->admin1)
             ->putJson("/api/admin/utilisateurs/{$this->admin1->id}/bloquer")
             ->assertStatus(422)
             ->assertJsonPath('message', 'Vous ne pouvez pas bloquer votre propre compte.');

        $this->en($this->admin1)->putJson("/api/admin/utilisateurs/{$this->admin2->id}/bloquer")->assertOk();
        $this->en($this->admin1)
             ->putJson("/api/admin/utilisateurs/{$this->admin2->id}/bloquer")
             ->assertStatus(422)
             ->assertJsonPath('message', 'Ce compte est déjà bloqué.');
    }
}

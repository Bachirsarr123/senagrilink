<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * Concurrence réelle (H1, R8) — PostgreSQL uniquement : SQLite ignore FOR UPDATE.
 *
 * Un second processus PHP ouvre une transaction, verrouille les lignes et applique
 * l'opération concurrente, garde le verrou ~1,5 s puis valide. Pendant ce temps,
 * la requête testée doit attendre le verrou et relire l'état à jour.
 *
 * DÉSACTIVÉ PAR DÉFAUT. Ce test recrée le schéma (migrate:fresh) : à n'exécuter
 * que sur une base PostgreSQL de test dédiée, jamais sur une base réelle. Il est
 * ignoré si DB_DATABASE ne contient pas « test » :
 *
 *   SENAGRILINK_TESTS_CONCURRENCE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 \
 *   DB_PORT=5432 DB_DATABASE=senagrilink_test DB_USERNAME=... DB_PASSWORD=... \
 *   php artisan test --filter=ConcurrencePostgresTest
 */
class ConcurrencePostgresTest extends TestCase
{
    use ConstruitParcours, DatabaseMigrations;

    private const DUREE_VERROU_US = 1_500_000;

    protected function setUp(): void
    {
        if (getenv('SENAGRILINK_TESTS_CONCURRENCE') !== '1' || getenv('DB_CONNECTION') !== 'pgsql') {
            $this->markTestSkipped('Test de concurrence PostgreSQL désactivé (voir le bloc de documentation).');
        }

        // Garde-fou : migrate:fresh efface la base. On refuse toute base dont le nom
        // ne désigne pas explicitement une base de test (ex. senagrilink_test).
        if (! preg_match('/test/i', (string) getenv('DB_DATABASE'))) {
            $this->markTestSkipped('DB_DATABASE doit désigner une base de test dédiée (nom contenant « test »).');
        }

        parent::setUp();
    }

    /**
     * Lance un processus qui exécute $requetes dans une transaction, signale qu'il
     * tient les verrous, attend puis valide. Retourne une fonction d'attente de fin.
     */
    private function transactionConcurrente(array $requetes): \Closure
    {
        $c   = config('database.connections.pgsql');
        $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']};sslmode=" . ($c['sslmode'] ?? 'prefer');

        $code = '$p = new PDO($argv[1], $argv[2], $argv[3], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
              . '$p->beginTransaction();'
              . 'foreach (json_decode($argv[4], true) as $sql) { $p->query($sql)->fetchAll(); }'
              . 'fwrite(STDOUT, "verrou\n"); fflush(STDOUT); usleep((int) $argv[5]); $p->commit(); fwrite(STDOUT, "fin\n");';

        $processus = proc_open(
            [PHP_BINARY, '-r', $code, $dsn, (string) $c['username'], (string) $c['password'], json_encode($requetes), (string) self::DUREE_VERROU_US],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tubes
        );

        // stderr n'est lu qu'en cas d'échec : le lire ici attendrait la fin du processus
        if (fgets($tubes[1]) !== "verrou\n") {
            $this->fail('La transaction concurrente n\'a pas pris ses verrous : ' . stream_get_contents($tubes[2]));
        }

        return function () use ($processus, $tubes) {
            $sortie = stream_get_contents($tubes[1]);
            $erreur = stream_get_contents($tubes[2]);
            $this->assertSame(0, proc_close($processus), $erreur);
            $this->assertStringContainsString('fin', $sortie);
        };
    }

    public function test_h1_meme_commande_confirmee_une_seule_fois(): void
    {
        $p = $this->parcours();
        $commande = $this->commande($p->acheteur, $p->stock);           // en_attente, 10 kg ; stock 80

        $fin = $this->transactionConcurrente([
            "SELECT id FROM commandes WHERE id = {$commande->id} FOR UPDATE",
            "UPDATE commandes SET statut = 'confirmee' WHERE id = {$commande->id}",
            "UPDATE stocks SET quantite = quantite - 10 WHERE id = {$p->stock->id}",
        ]);

        $debut = microtime(true);
        $this->en($p->gestionnaire)->putJson("/api/commandes/{$commande->id}/confirmer")
             ->assertStatus(422)
             ->assertJsonPath('statut_actuel', 'confirmee');
        $attente = microtime(true) - $debut;
        $fin();

        $this->assertGreaterThan(0.5, $attente, 'La requête aurait dû attendre le verrou.');
        $this->assertEquals(70, Stock::find($p->stock->id)->quantite); // déduit une seule fois
    }

    public function test_h1_deux_commandes_sur_le_meme_stock_sans_mise_a_jour_perdue(): void
    {
        $p  = $this->parcours();
        $c1 = $this->commande($p->acheteur, $p->stock);
        $c2 = $this->commande($p->acheteur, $p->stock);

        $fin = $this->transactionConcurrente([
            "SELECT id FROM stocks WHERE id = {$p->stock->id} FOR UPDATE",
            "UPDATE commandes SET statut = 'confirmee' WHERE id = {$c1->id}",
            "UPDATE stocks SET quantite = quantite - 10 WHERE id = {$p->stock->id}",
        ]);

        $this->en($p->gestionnaire)->putJson("/api/commandes/{$c2->id}/confirmer")
             ->assertOk()
             ->assertJsonPath('stock_restant', 60);
        $fin();

        $this->assertEquals(60, Stock::find($p->stock->id)->quantite); // 80 - 10 - 10
    }

    public function test_r8_blocage_croise_garde_un_administrateur_actif(): void
    {
        $admin1 = Utilisateur::factory()->administrateur()->create();
        $admin2 = Utilisateur::factory()->administrateur()->create();
        $requete = $this->en($admin1); // jeton émis avant le blocage concurrent

        // L'admin 2 bloque l'admin 1 (même verrouillage que AdminController::bloquer)
        $fin = $this->transactionConcurrente([
            "SELECT id FROM utilisateurs WHERE role = 'administrateur' AND statut = 'actif' ORDER BY id FOR UPDATE",
            "UPDATE utilisateurs SET statut = 'bloque' WHERE id = {$admin1->id}",
        ]);

        $requete->putJson("/api/admin/utilisateurs/{$admin2->id}/bloquer")
                ->assertStatus(422)
                ->assertJsonPath('message', 'Impossible de bloquer le dernier administrateur actif.');
        $fin();

        $this->assertSame('actif', $admin2->fresh()->statut);
    }
}

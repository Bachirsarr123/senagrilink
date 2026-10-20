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

    /** Durée minimale attendue derrière le verrou, preuve que la requête a bien été sérialisée. */
    private function chrono(\Closure $requete): float
    {
        $debut = microtime(true);
        $requete();

        return microtime(true) - $debut;
    }

    public function test_p05_marchandise_receptionnee_une_seule_fois(): void
    {
        $p     = $this->parcours();
        $prod  = $this->production($p->producteur);
        $resa  = $this->reservation($prod, $p->gestionnaire, 'arrivee_entrepot');
        $avant = Stock::count();

        // Une première validation est en cours (même verrou que validerMarchandise)
        $fin = $this->transactionConcurrente([
            "SELECT id FROM reservations WHERE id = {$resa->id} FOR UPDATE",
            "UPDATE reservations SET statut = 'enregistree' WHERE id = {$resa->id}",
            "INSERT INTO stocks (entrepot_id, production_id, produit, quantite, statut) VALUES ({$resa->entrepot_id}, {$prod->id}, 'riz', 100, 'disponible')",
        ]);

        $attente = $this->chrono(fn () => $this->en($p->gestionnaire)
            ->putJson("/api/reservations/{$resa->id}/valider", ['quantite_reelle' => 100])
            ->assertStatus(422));
        $fin();

        $this->assertGreaterThan(0.5, $attente, 'La validation aurait dû attendre le verrou.');
        $this->assertSame($avant + 1, Stock::count()); // une seule ligne de stock pour le lot
    }

    public function test_p08_sortie_de_stock_sans_mise_a_jour_perdue(): void
    {
        $p = $this->parcours(); // stock de 80 kg

        // Une confirmation de commande déduit 10 kg sous verrou
        $fin = $this->transactionConcurrente([
            "SELECT id FROM stocks WHERE id = {$p->stock->id} FOR UPDATE",
            "UPDATE stocks SET quantite = quantite - 10 WHERE id = {$p->stock->id}",
        ]);

        // Sortie de 75 kg : possible sur l'ancienne lecture (80), impossible sur la réelle (70)
        $attente = $this->chrono(fn () => $this->en($p->gestionnaire)
            ->postJson('/api/stocks/sortie', ['stock_id' => $p->stock->id, 'quantite_sortie' => 75])
            ->assertStatus(422));
        $fin();

        $this->assertGreaterThan(0.5, $attente);
        $this->assertEquals(70, Stock::find($p->stock->id)->quantite);
    }

    public function test_p10_capacite_dentrepot_non_depassee_par_deux_demandes_simultanees(): void
    {
        $p = $this->parcours();
        $p->gestionnaire->entrepot->update(['capacite' => 180]); // 80 kg déjà en stock → 100 disponibles
        $prod = $this->production($p->producteur);
        $avant = \App\Models\Reservation::count();

        // Une autre demande de 60 kg est en cours de création (même verrou sur l'entrepôt)
        $fin = $this->transactionConcurrente([
            "SELECT id FROM entrepots WHERE id = {$p->gestionnaire->entrepot->id} FOR UPDATE",
            "INSERT INTO reservations (entrepot_id, producteur_id, production_id, produit, quantite_reservee, date_debut, date_reservation, statut) "
                ."VALUES ({$p->gestionnaire->entrepot->id}, {$prod->producteur_id}, {$prod->id}, 'riz', 60, '2026-06-02', NOW(), 'en_attente')",
        ]);

        $attente = $this->chrono(fn () => $this->en($p->producteur)->postJson('/api/reservations', [
            'entrepot_id' => $p->gestionnaire->entrepot->id, 'produit' => 'riz', 'quantite_reservee' => 60, 'date_debut' => '2026-06-02',
        ])->assertStatus(422));
        $fin();

        $this->assertGreaterThan(0.5, $attente);
        $this->assertSame($avant + 1, \App\Models\Reservation::count());
    }

    public function test_p25_une_seule_livraison_par_commande(): void
    {
        $p = $this->parcours('en_attente');
        $p->livraison->delete();
        $avant = \App\Models\Livraison::count();

        $fin = $this->transactionConcurrente([
            "SELECT id FROM commandes WHERE id = {$p->commande->id} FOR UPDATE",
            "INSERT INTO livraisons (commande_id, transporteur_id, origine, destination, statut) "
                ."VALUES ({$p->commande->id}, {$p->transporteur->transporteur->id}, 'E', 'D', 'en_attente')",
        ]);

        $attente = $this->chrono(fn () => $this->en($p->gestionnaire)->postJson('/api/livraisons', [
            'commande_id' => $p->commande->id, 'transporteur_id' => $p->transporteur->transporteur->id, 'origine' => 'E', 'destination' => 'D',
        ])->assertStatus(422));
        $fin();

        $this->assertGreaterThan(0.5, $attente);
        $this->assertSame($avant + 1, \App\Models\Livraison::count());
    }

    public function test_arrivee_refusee_au_transporteur_remplace_pendant_la_requete(): void
    {
        $p      = $this->parcours();
        $autre  = Utilisateur::factory()->transporteur()->create();
        $resa   = $this->reservation($this->production($p->producteur), $p->gestionnaire, 'confirmee');
        $resa->update(['transporteur_id' => $p->transporteur->transporteur->id]);
        $requete = $this->en($p->transporteur);

        // Réassignation simultanée par le gestionnaire
        $fin = $this->transactionConcurrente([
            "SELECT id FROM reservations WHERE id = {$resa->id} FOR UPDATE",
            "UPDATE reservations SET transporteur_id = {$autre->transporteur->id} WHERE id = {$resa->id}",
        ]);

        $requete->putJson("/api/reservations/{$resa->id}/arrivee")->assertStatus(403);
        $fin();

        $this->assertSame('confirmee', $resa->fresh()->statut);
    }

    public function test_idempotence_sans_transaction_englobante_sous_postgresql(): void
    {
        // Chemin de production : pas de transaction de test autour de la requête,
        // la violation d'unicité est rattrapée au premier niveau de transaction.
        $p   = $this->parcours();
        $cle = 'concurrence-cle-0001';
        $envoyer = fn () => $this->en($p->acheteur)->withHeader('Idempotency-Key', $cle)
            ->postJson('/api/commandes', ['stock_id' => $p->stock->id, 'quantite' => 3]);

        $premiere = $envoyer()->assertStatus(201);
        $seconde  = $envoyer()->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($premiere->json('commande.id'), $seconde->json('commande.id'));
        $this->assertSame(1, \App\Models\Commande::where('quantite', 3)->count());
    }
}

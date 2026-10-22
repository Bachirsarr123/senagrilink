<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/** Migration 000020 sur des réservations créées avant le lot 2. */
class RepriseCircuitDepotTest extends TestCase
{
    use ConstruitParcours, DatabaseMigrations;

    private const MIGRATION = 'database/migrations/2025_01_01_000020_create_circuit_depot.php';

    public function test_reprise_des_reservations_existantes(): void
    {
        $producteur   = Utilisateur::factory()->create();
        $gestionnaire = Utilisateur::factory()->gestionnaire()->create();
        $transporteur = Utilisateur::factory()->transporteur()->create();
        $lot          = $this->production($producteur);

        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);

        $nouvelle = fn (string $statut, ?int $t = null) => DB::table('reservations')->insertGetId([
            'entrepot_id' => $gestionnaire->entrepot->id, 'producteur_id' => $lot->producteur_id, 'production_id' => $lot->id,
            'transporteur_id' => $t, 'produit' => 'riz', 'quantite_reservee' => 40, 'date_debut' => '2026-06-02',
            'date_reservation' => now(), 'statut' => $statut,
        ]);
        $enregistree = $nouvelle('enregistree');
        $enCours     = $nouvelle('confirmee', $transporteur->transporteur->id);
        $stockId     = DB::table('stocks')->insertGetId(['entrepot_id' => $gestionnaire->entrepot->id, 'production_id' => $lot->id, 'produit' => 'riz', 'quantite' => 38, 'statut' => 'disponible']);
        DB::table('mouvements_stock')->insert([
            'stock_id' => $stockId, 'type' => 'entree', 'variation_physique' => 38, 'variation_reservee' => 0,
            'physique_apres' => 38, 'reservee_apres' => 0, 'operation_type' => 'reservation', 'operation_id' => $enregistree,
        ]);

        Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]);

        $this->assertSame(38.0, Reservation::find($enregistree)->quantite_recue);
        $r = Reservation::find($enCours);
        $this->assertSame(['confirmee', 'transporteur', null], [$r->statut, $r->mode_transport, $r->mission_acceptee_le]);

        // Le transporteur déjà affecté accepte la mission, puis le circuit continue normalement
        $this->en($transporteur)->putJson("/api/reservations/{$enCours}/accepter-mission")->assertOk()
             ->assertJsonPath('reservation.statut', 'prise_en_charge');
    }
}

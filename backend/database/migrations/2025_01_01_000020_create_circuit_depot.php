<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 2 — circuit « récolte → entrepôt » complet :
     *  - statut de réservation en texte (nouvelles étapes : prise_en_charge,
     *    au_point_collecte, chargee, en_route, reception_refusee) ;
     *  - mode de transport choisi par le producteur (transporteur | producteur) ;
     *  - acceptation de la mission par le transporteur ;
     *  - réception détaillée : quantité reçue, état de la marchandise ;
     *  - position du producteur et de l'acheteur sur une carte (profil).
     *
     * Données existantes : mode « transporteur » par défaut ; une réservation
     * confirmée avec transporteur reste « confirmee » (le transporteur accepte
     * la mission) ; quantité reçue reprise du stock créé à la réception.
     */
    public function up(): void
    {
        $this->statutEnTexte(30);

        Schema::table('reservations', function (Blueprint $table) {
            $table->string('mode_transport', 20)->default('transporteur');
            $table->timestamp('mission_acceptee_le')->nullable();
            $table->float('quantite_recue')->nullable();
            $table->string('etat_marchandise', 20)->nullable();
        });

        Schema::table('producteurs', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });

        Schema::table('acheteurs_gros', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });

        foreach (DB::table('reservations')->where('statut', 'enregistree')->get(['id', 'production_id', 'entrepot_id']) as $r) {
            $recue = DB::table('mouvements_stock')
                ->where('type', 'entree')->where('operation_type', 'reservation')->where('operation_id', $r->id)
                ->sum('variation_physique');
            DB::table('reservations')->where('id', $r->id)->update(['quantite_recue' => $recue ?: null]);
        }
    }

    public function down(): void
    {
        // Les nouvelles étapes n'existaient pas : elles reviennent à l'étape connue la plus proche.
        DB::table('reservations')->whereIn('statut', ['prise_en_charge', 'au_point_collecte', 'chargee', 'en_route'])->update(['statut' => 'confirmee']);
        DB::table('reservations')->where('statut', 'reception_refusee')->update(['statut' => 'refusee']);

        Schema::table('acheteurs_gros', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude']));
        Schema::table('producteurs', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude']));
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn(['mode_transport', 'mission_acceptee_le', 'quantite_recue', 'etat_marchandise']));

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_statut_check CHECK (statut IN ('en_attente','confirmee','refusee','annulee','arrivee_entrepot','enregistree'))");
        }
    }

    /** L'enum SQL ne peut pas recevoir de nouvelles valeurs : la colonne devient du texte. */
    private function statutEnTexte(int $longueur): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_statut_check');
            DB::statement("ALTER TABLE reservations ALTER COLUMN statut TYPE varchar({$longueur})");

            return;
        }

        Schema::table('reservations', function (Blueprint $table) use ($longueur) {
            $table->string('statut', $longueur)->default('en_attente')->change();
        });
    }
};

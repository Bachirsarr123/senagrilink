<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 4 — GPS fiable :
     *  - chaque position est liée à la mission suivie (livraison ou acheminement
     *    vers l'entrepôt) et à la personne qui conduit (transporteur, ou producteur
     *    qui livre lui-même) : un acteur ne voit que la mission qui le concerne ;
     *  - heure de prise sur le téléphone (positions gardées hors connexion puis
     *    renvoyées), précision, vitesse et cap ;
     *  - estimation d'arrivée gardée sur la mission (recalculée périodiquement).
     *
     * Reprise : les anciennes positions ne sont liées à aucune mission ; elles ne
     * sont plus affichées et partiront avec la purge des 30 jours.
     */
    public function up(): void
    {
        Schema::table('positions_gps', function (Blueprint $table) {
            $table->string('sujet_type', 20)->nullable();
            $table->unsignedBigInteger('sujet_id')->nullable();
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->float('precision_m')->nullable();
            $table->float('vitesse_kmh')->nullable();
            $table->float('cap')->nullable();
            $table->timestamp('recue_le')->nullable();

            $table->index(['sujet_type', 'sujet_id', 'timestamp']);
            $table->index('timestamp');
        });

        // Un producteur qui livre lui-même n'a pas de profil transporteur.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE positions_gps ALTER COLUMN transporteur_id DROP NOT NULL');
        } else {
            Schema::table('positions_gps', fn (Blueprint $t) => $t->unsignedBigInteger('transporteur_id')->nullable()->change());
        }

        // Heures réelles de départ et d'arrivée de l'acheminement (comme pour les livraisons).
        Schema::table('reservations', function (Blueprint $t) {
            $t->timestamp('parti_le')->nullable();
            $t->timestamp('arrive_le')->nullable();
        });

        foreach (['livraisons', 'reservations'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('arrivee_estimee_le')->nullable();
                $t->float('distance_restante_km')->nullable();
                $t->json('trajet')->nullable();
                $t->timestamp('estimation_le')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('reservations', fn (Blueprint $t) => $t->dropColumn(['parti_le', 'arrive_le']));
        foreach (['livraisons', 'reservations'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['arrivee_estimee_le', 'distance_restante_km', 'trajet', 'estimation_le']));
        }

        DB::table('positions_gps')->whereNull('transporteur_id')->delete();

        Schema::table('positions_gps', function (Blueprint $table) {
            $table->dropIndex(['sujet_type', 'sujet_id', 'timestamp']);
            $table->dropIndex(['timestamp']);
            $table->dropConstrainedForeignId('utilisateur_id');
            $table->dropColumn(['sujet_type', 'sujet_id', 'precision_m', 'vitesse_kmh', 'cap', 'recue_le']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE positions_gps ALTER COLUMN transporteur_id SET NOT NULL');
        }
    }
};

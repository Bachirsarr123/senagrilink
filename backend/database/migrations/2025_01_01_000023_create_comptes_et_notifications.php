<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 6 — comptes et notifications :
     *  - statut du compte en texte : actif, bloque, en_attente_validation (Q9 :
     *    gestionnaires et transporteurs validés par l'administrateur), refuse ;
     *    qui a validé ou refusé, quand, et pourquoi ;
     *  - appareils (jetons Firebase) pour les notifications sur téléphone (Q15) ;
     *  - adresse IP dans le journal d'audit.
     *
     * Reprise : les comptes existants gardent leur statut (actif ou bloqué).
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE utilisateurs DROP CONSTRAINT IF EXISTS utilisateurs_statut_check');
            DB::statement('ALTER TABLE utilisateurs ALTER COLUMN statut TYPE varchar(30)');
        } else {
            Schema::table('utilisateurs', fn (Blueprint $t) => $t->string('statut', 30)->default('actif')->change());
        }

        Schema::table('utilisateurs', function (Blueprint $table) {
            $table->foreignId('valide_par')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->string('motif_refus', 500)->nullable();
        });

        Schema::create('appareils', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('jeton', 512)->unique();
            $table->string('plateforme', 20)->default('android');
            $table->timestamp('derniere_activite')->nullable();
            $table->timestamps();
        });

        Schema::table('historique_operations', fn (Blueprint $t) => $t->string('adresse_ip', 45)->nullable());
    }

    public function down(): void
    {
        Schema::table('historique_operations', fn (Blueprint $t) => $t->dropColumn('adresse_ip'));
        Schema::dropIfExists('appareils');

        Schema::table('utilisateurs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('valide_par');
            $table->dropColumn(['valide_le', 'motif_refus']);
        });

        // Comptes jamais validés ou refusés : ils ne peuvent pas se connecter, ils redeviennent bloqués.
        DB::table('utilisateurs')->whereIn('statut', ['en_attente_validation', 'refuse'])->update(['statut' => 'bloque']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE utilisateurs ADD CONSTRAINT utilisateurs_statut_check CHECK (statut IN ('actif', 'bloque'))");
        }
    }
};

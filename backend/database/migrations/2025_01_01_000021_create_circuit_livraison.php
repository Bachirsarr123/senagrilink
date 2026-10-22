<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 3 — circuit « entrepôt → acheteur » et incidents :
     *  - statuts de commande et de livraison en texte (préparation, chargement,
     *    arrivée, réception par l'acheteur, litige, retour) ;
     *  - panier (commandes passées ensemble) et code de réception à 4 chiffres ;
     *  - acceptation de la mission, heures réelles de départ et d'arrivée ;
     *  - incidents pour les deux circuits (déclaration, responsable, décision).
     *
     * Reprise (comptes de test) : commande « livree » → « receptionnee » ;
     * livraison « probleme » → « en_cours » avec un incident ouvert.
     */
    public function up(): void
    {
        $this->enTexte('commandes', 30);
        $this->enTexte('livraisons', 30);

        Schema::table('commandes', function (Blueprint $table) {
            $table->string('panier', 40)->nullable()->index();
            $table->string('code_reception', 4)->nullable();
        });

        Schema::table('livraisons', function (Blueprint $table) {
            $table->timestamp('mission_acceptee_le')->nullable();
            $table->timestamp('parti_le')->nullable();
            $table->timestamp('arrive_le')->nullable();
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('sujet_type', 20);
            $table->unsignedBigInteger('sujet_id');
            $table->string('type', 30);
            $table->string('description', 1000)->nullable();
            $table->string('statut', 20)->default('ouvert');
            $table->foreignId('declare_par')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->string('decision', 30)->nullable();
            $table->string('motif_decision', 1000)->nullable();
            $table->foreignId('resolu_par')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamp('resolu_le')->nullable();
            $table->timestamps();

            $table->index(['sujet_type', 'sujet_id']);
            $table->index('statut');
        });

        DB::table('commandes')->where('statut', 'livree')->update(['statut' => 'receptionnee']);
        // Commande confirmée déjà dotée d'une livraison : prête (livraison à accepter) ou en livraison (partie).
        $avecLivraison = fn (array $statuts) => DB::table('livraisons')->whereIn('statut', $statuts)->select('commande_id');
        DB::table('commandes')->where('statut', 'confirmee')->whereIn('id', $avecLivraison(['en_attente']))->update(['statut' => 'prete']);
        DB::table('commandes')->where('statut', 'confirmee')->whereIn('id', $avecLivraison(['en_cours', 'probleme']))->update(['statut' => 'en_livraison']);
        DB::table('livraisons')->whereIn('statut', ['en_cours', 'probleme'])->whereNull('mission_acceptee_le')
            ->update(['mission_acceptee_le' => DB::raw('date_depart'), 'parti_le' => DB::raw('date_depart')]);
        foreach (DB::table('livraisons')->where('statut', 'probleme')->pluck('id') as $id) {
            DB::table('livraisons')->where('id', $id)->update(['statut' => 'en_cours']);
            DB::table('incidents')->insert([
                'sujet_type' => 'livraison', 'sujet_id' => $id, 'type' => 'autre', 'statut' => 'ouvert',
                'description' => 'Problème signalé avant la mise à jour (sans précision).', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $ouverts = DB::table('incidents')->where('sujet_type', 'livraison')->where('statut', 'ouvert')->pluck('sujet_id');
        Schema::dropIfExists('incidents');

        DB::table('livraisons')->whereIn('id', $ouverts)->whereNotIn('statut', ['livree'])->update(['statut' => 'probleme']);
        DB::table('livraisons')->whereIn('statut', ['prise_en_charge', 'chargee'])->update(['statut' => 'en_attente']);
        DB::table('livraisons')->whereIn('statut', ['arrivee'])->update(['statut' => 'en_cours']);
        DB::table('livraisons')->whereIn('statut', ['annulee', 'retournee'])->update(['statut' => 'probleme']);
        DB::table('commandes')->whereIn('statut', ['receptionnee', 'litige'])->update(['statut' => 'livree']);
        DB::table('commandes')->whereIn('statut', ['en_preparation', 'prete', 'en_livraison'])->update(['statut' => 'confirmee']);

        Schema::table('livraisons', fn (Blueprint $t) => $t->dropColumn(['mission_acceptee_le', 'parti_le', 'arrive_le']));
        Schema::table('commandes', function (Blueprint $t) {
            $t->dropIndex(['panier']);
            $t->dropColumn(['panier', 'code_reception']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE commandes ADD CONSTRAINT commandes_statut_check CHECK (statut IN ('en_attente','confirmee','annulee','livree'))");
            DB::statement("ALTER TABLE livraisons ADD CONSTRAINT livraisons_statut_check CHECK (statut IN ('en_attente','en_cours','livree','probleme'))");
        }
    }

    /** L'enum SQL ne peut pas recevoir de nouvelles valeurs : la colonne devient du texte. */
    private function enTexte(string $tableNom, int $longueur): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$tableNom} DROP CONSTRAINT IF EXISTS {$tableNom}_statut_check");
            DB::statement("ALTER TABLE {$tableNom} ALTER COLUMN statut TYPE varchar({$longueur})");

            return;
        }

        Schema::table($tableNom, fn (Blueprint $t) => $t->string('statut', $longueur)->default('en_attente')->change());
    }
};

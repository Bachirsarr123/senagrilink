<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 1 — fondations du stock :
     *  - prix au kilo fixé par le producteur sur son lot, figé sur la commande ;
     *  - stock réservé (commandes confirmées non parties) en plus du stock physique ;
     *  - journal des mouvements : toute variation du stock y laisse une ligne.
     *
     * Reprise des données existantes (comptes de test) : chaque stock reçoit un
     * mouvement d'ouverture ; une commande confirmée dont la livraison n'est pas
     * partie avait été déduite à la confirmation (ancienne règle) : sa quantité
     * redevient physique et réservée, pour être sortie une seule fois au départ.
     */
    public function up(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->decimal('prix_unitaire', 12, 2)->nullable();
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->float('quantite_reservee')->default(0);
        });

        Schema::table('commandes', function (Blueprint $table) {
            $table->decimal('prix_unitaire', 12, 2)->nullable();
            // null : rien de réservé ; reserve : quantité réservée ; sorti : quantité
            // sortie du stock (départ) ; libere : réservation rendue (annulation).
            $table->string('etat_stock', 20)->nullable();
        });

        Schema::create('mouvements_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained('stocks')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('motif_type', 30)->nullable();
            $table->float('variation_physique')->default(0);
            $table->float('variation_reservee')->default(0);
            $table->float('physique_apres');
            $table->float('reservee_apres');
            $table->string('operation_type', 20)->nullable();
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->string('motif', 500)->nullable();
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['stock_id', 'id']);
            $table->index(['operation_type', 'operation_id']);
        });

        $this->reprendreLesDonnees();
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_stock');

        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn(['prix_unitaire', 'etat_stock']);
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn('quantite_reservee');
        });

        Schema::table('productions', function (Blueprint $table) {
            $table->dropColumn('prix_unitaire');
        });
    }

    private function reprendreLesDonnees(): void
    {
        foreach (DB::table('stocks')->orderBy('id')->get() as $stock) {
            DB::table('mouvements_stock')->insert([
                'stock_id'           => $stock->id,
                'type'               => 'ouverture',
                'variation_physique' => $stock->quantite,
                'variation_reservee' => 0,
                'physique_apres'     => $stock->quantite,
                'reservee_apres'     => 0,
                'motif'              => 'Reprise du stock existant',
            ]);
        }

        $confirmees = DB::table('commandes')
            ->leftJoin('livraisons', 'livraisons.commande_id', '=', 'commandes.id')
            ->where('commandes.statut', 'confirmee')
            ->whereNotNull('commandes.stock_id')
            ->select('commandes.id', 'commandes.stock_id', 'commandes.quantite', 'livraisons.statut as statut_livraison')
            ->orderBy('commandes.id')
            ->get();

        foreach ($confirmees as $commande) {
            $partie = in_array($commande->statut_livraison, ['en_cours', 'livree', 'probleme'], true);

            if ($partie) {
                DB::table('commandes')->where('id', $commande->id)->update(['etat_stock' => 'sorti']);
                continue;
            }

            $stock    = DB::table('stocks')->where('id', $commande->stock_id)->first();
            $physique = $stock->quantite + $commande->quantite;
            $reservee = $stock->quantite_reservee + $commande->quantite;

            DB::table('stocks')->where('id', $stock->id)->update([
                'quantite'          => $physique,
                'quantite_reservee' => $reservee,
                'statut'            => 'disponible',
                'date_sortie'       => null,
            ]);

            DB::table('mouvements_stock')->insert([
                'stock_id'           => $stock->id,
                'type'               => 'reservation',
                'variation_physique' => $commande->quantite,
                'variation_reservee' => $commande->quantite,
                'physique_apres'     => $physique,
                'reservee_apres'     => $reservee,
                'operation_type'     => 'commande',
                'operation_id'       => $commande->id,
                'motif'              => 'Reprise : commande confirmée non partie, déduction remplacée par une réservation',
            ]);

            DB::table('commandes')->where('id', $commande->id)->update(['etat_stock' => 'reserve']);
        }

        $livrees = DB::table('livraisons')->where('statut', 'livree')->pluck('commande_id');
        DB::table('commandes')->whereIn('id', $livrees)->where('statut', 'confirmee')->update(['statut' => 'livree']);
    }
};

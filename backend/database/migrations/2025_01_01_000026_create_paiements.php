<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 12 : paiements. L'acheteur paie la marchandise (prix du producteur) et la
 * livraison ; l'argent est mis de côté jusqu'à la réception, puis réparti :
 * producteur (marchandise − frais d'entrepôt − commission), entrepôt (frais de
 * stockage), transporteur (livraison), SenAgriLink (commission). Montants figés
 * à la commande. Fournisseur « simulation » en attendant Wave Business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entrepots', fn (Blueprint $t) => $t->decimal('frais_stockage_kg', 10, 2)->default(5));

        Schema::table('utilisateurs', function (Blueprint $t) {
            $t->string('moyen_paiement', 20)->nullable();   // wave, orange_money
            $t->string('numero_paiement', 20)->nullable();  // numéro où recevoir l'argent
        });

        Schema::create('paiements', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40)->unique();
            $t->foreignId('acheteur_id')->constrained('acheteurs_gros');
            $t->decimal('montant', 12, 2);
            $t->string('moyen', 20);                         // wave, orange_money, especes
            $t->string('fournisseur', 20);                   // simulation, wave, livraison
            $t->string('statut', 20)->default('initie');     // initie, reussi, echoue, rembourse
            $t->string('reference_externe', 100)->nullable();
            $t->string('telephone', 20)->nullable();
            $t->timestamp('paye_le')->nullable();
            $t->timestamp('rembourse_le')->nullable();
            $t->timestamps();
        });

        Schema::table('commandes', function (Blueprint $t) {
            $t->decimal('frais_livraison', 12, 2)->default(0);
            $t->decimal('frais_stockage', 12, 2)->default(0);
            $t->decimal('commission', 12, 2)->default(0);
            $t->decimal('montant_producteur', 12, 2)->default(0);
            $t->decimal('total_a_payer', 12, 2)->default(0);
            $t->string('statut_paiement', 20)->default('a_payer'); // a_payer, paye, a_la_livraison, libere, litige, rembourse
            $t->foreignId('paiement_id')->nullable()->constrained('paiements')->nullOnDelete();
        });

        Schema::create('versements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            $t->foreignId('paiement_id')->nullable()->constrained('paiements')->nullOnDelete();
            $t->string('beneficiaire', 20);                  // producteur, entrepot, transporteur, plateforme
            $t->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->decimal('montant', 12, 2);
            $t->string('statut', 20);                        // bloque, verse, a_regler, regle, annule
            $t->string('moyen', 20)->nullable();
            $t->string('telephone', 20)->nullable();
            $t->string('reference_externe', 100)->nullable();
            $t->timestamp('verse_le')->nullable();
            $t->timestamps();
            $t->index(['utilisateur_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versements');
        Schema::table('commandes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('paiement_id');
            $t->dropColumn(['frais_livraison', 'frais_stockage', 'commission', 'montant_producteur', 'total_a_payer', 'statut_paiement']);
        });
        Schema::dropIfExists('paiements');
        Schema::table('utilisateurs', fn (Blueprint $t) => $t->dropColumn(['moyen_paiement', 'numero_paiement']));
        Schema::table('entrepots', fn (Blueprint $t) => $t->dropColumn('frais_stockage_kg'));
    }
};

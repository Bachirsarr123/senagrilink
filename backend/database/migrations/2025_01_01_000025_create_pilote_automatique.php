<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 11 : pilote automatique de l'entrepôt. Le système accepte les dépôts quand
 * la place le permet, confirme les commandes (stock déjà réservé) et confie les
 * missions au transporteur libre le plus proche, avec relance au suivant après
 * un refus ou sans réponse. Chaque règle se coupe dans les réglages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entrepots', function (Blueprint $table) {
            $table->boolean('auto_accepter_depots')->default(true);
            $table->boolean('auto_confirmer_commandes')->default(true);
            $table->boolean('auto_affecter_transporteurs')->default(true);
            $table->unsignedSmallInteger('delai_reponse_min')->default(10);
        });
    }

    public function down(): void
    {
        Schema::table('entrepots', fn (Blueprint $table) => $table->dropColumn([
            'auto_accepter_depots', 'auto_confirmer_commandes', 'auto_affecter_transporteurs', 'delai_reponse_min',
        ]));
    }
};

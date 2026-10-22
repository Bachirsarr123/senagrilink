<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 8 : chaque mission porte son lieu exact. Le lieu de prise en charge d'un
 * dépôt et le lieu de livraison d'une commande sont pris automatiquement (position
 * du téléphone ou lieu habituel du profil), et restent modifiables sur la carte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->decimal('collecte_latitude', 10, 7)->nullable();
            $table->decimal('collecte_longitude', 10, 7)->nullable();
            $table->string('collecte_adresse', 255)->nullable();
        });

        Schema::table('commandes', function (Blueprint $table) {
            $table->decimal('livraison_latitude', 10, 7)->nullable();
            $table->decimal('livraison_longitude', 10, 7)->nullable();
            $table->string('livraison_adresse', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commandes', fn (Blueprint $table) => $table->dropColumn(['livraison_latitude', 'livraison_longitude', 'livraison_adresse']));
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn(['collecte_latitude', 'collecte_longitude', 'collecte_adresse']));
    }
};

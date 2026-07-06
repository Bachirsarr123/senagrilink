<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Point de départ sur la carte de suivi (entrepôt).
        Schema::table('entrepots', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('localisation');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        // Point d'arrivée sur la carte de suivi (adresse de l'acheteur).
        Schema::table('livraisons', function (Blueprint $table) {
            $table->decimal('destination_latitude', 10, 7)->nullable()->after('destination');
            $table->decimal('destination_longitude', 10, 7)->nullable()->after('destination_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('entrepots', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });

        Schema::table('livraisons', function (Blueprint $table) {
            $table->dropColumn(['destination_latitude', 'destination_longitude']);
        });
    }
};

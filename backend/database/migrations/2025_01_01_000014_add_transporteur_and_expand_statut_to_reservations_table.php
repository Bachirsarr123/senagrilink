<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('transporteur_id')
                ->nullable()
                ->after('production_id')
                ->constrained('transporteurs')
                ->nullOnDelete();
        });

        // L'enum SQL ne peut pas être étendu in-place : on recrée la colonne.
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('statut');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->enum('statut', [
                'en_attente',
                'confirmee',
                'refusee',
                'annulee',
                'arrivee_entrepot',
                'enregistree',
            ])->default('en_attente')->after('date_reservation');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transporteur_id');
            $table->dropColumn('statut');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->enum('statut', ['en_attente', 'confirmee', 'refusee', 'annulee'])
                ->default('en_attente')
                ->after('date_reservation');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('numero_reservation', 50)->unique()->nullable();
            $table->foreignId('entrepot_id')->constrained('entrepots')->cascadeOnDelete();
            $table->foreignId('producteur_id')->constrained('producteurs')->cascadeOnDelete();
            $table->foreignId('production_id')->nullable()->constrained('productions')->nullOnDelete();
            $table->string('produit', 150);
            $table->float('quantite_reservee');
            $table->date('date_debut');
            $table->date('date_fin')->nullable();
            $table->timestamp('date_reservation')->useCurrent();
            $table->enum('statut', ['en_attente', 'confirmee', 'refusee', 'annulee'])->default('en_attente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livraisons', function (Blueprint $table) {
            $table->id();
            $table->string('numero_livraison', 50)->unique()->nullable();
            $table->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            $table->foreignId('transporteur_id')->nullable()->constrained('transporteurs')->nullOnDelete();
            $table->string('origine', 255)->nullable();
            $table->string('destination', 255)->nullable();
            $table->timestamp('date_depart')->nullable();
            $table->timestamp('date_livraison')->nullable();
            $table->enum('statut', ['en_attente', 'en_cours', 'livree', 'probleme'])->default('en_attente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livraisons');
    }
};

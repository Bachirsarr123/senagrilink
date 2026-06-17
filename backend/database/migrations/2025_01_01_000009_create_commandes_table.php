<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commandes', function (Blueprint $table) {
            $table->id();
            $table->string('numero_commande', 50)->unique()->nullable();
            $table->foreignId('acheteur_id')->constrained('acheteurs_gros')->cascadeOnDelete();
            $table->foreignId('stock_id')->nullable()->constrained('stocks')->nullOnDelete();
            $table->string('produit', 150);
            $table->float('quantite');
            $table->float('prix')->nullable();
            $table->timestamp('date_commande')->useCurrent();
            $table->enum('statut', ['en_attente', 'confirmee', 'annulee', 'livree'])->default('en_attente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commandes');
    }
};

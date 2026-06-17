<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entrepot_id')->constrained('entrepots')->cascadeOnDelete();
            $table->foreignId('production_id')->nullable()->constrained('productions')->nullOnDelete();
            $table->string('produit', 150);
            $table->float('quantite');
            $table->date('date_entree')->nullable();
            $table->date('date_sortie')->nullable();
            $table->float('seuil_alerte')->nullable();
            $table->string('statut', 30)->default('disponible');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};

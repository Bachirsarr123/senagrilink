<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producteur_id')->constrained('producteurs')->cascadeOnDelete();
            $table->string('code_tracabilite', 50)->unique();
            $table->string('type_culture', 100)->nullable();
            $table->float('superficie')->nullable();
            $table->date('date_recolte')->nullable();
            $table->float('quantite_estimee')->nullable();
            $table->float('quantite_reelle')->nullable();
            $table->string('statut', 30)->default('en_attente');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productions');
    }
};

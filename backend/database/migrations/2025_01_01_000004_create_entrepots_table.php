<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entrepots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('nom_entrepot', 150)->nullable();
            $table->float('capacite')->nullable();
            $table->string('localisation', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entrepots');
    }
};

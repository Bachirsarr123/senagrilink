<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utilisateurs', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100);
            $table->string('prenom', 100);
            $table->string('email', 150)->unique();
            $table->string('telephone', 20)->nullable();
            $table->string('adresse', 255)->nullable();
            $table->string('mot_de_passe', 255);
            $table->string('photo', 255)->nullable();
            $table->enum('role', [
                'producteur',
                'gestionnaire_entrepot',
                'acheteur_gros',
                'transporteur',
                'administrateur',
            ]);
            $table->enum('statut', ['actif', 'bloque'])->default('actif');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utilisateurs');
    }
};

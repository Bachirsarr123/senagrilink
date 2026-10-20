<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Requêtes de création déjà traitées, par clé d'idempotence (en-tête
     * Idempotency-Key) : une même soumission renvoyée après une erreur réseau
     * ne crée pas un second enregistrement. Table technique, purgée après 24 h ;
     * aucune donnée métier n'y est stockée hors la réponse JSON renvoyée.
     */
    public function up(): void
    {
        Schema::create('requetes_idempotentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('cle', 100);
            $table->string('empreinte', 64);
            $table->unsignedSmallInteger('statut_http')->nullable();
            $table->longText('reponse')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['utilisateur_id', 'cle']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requetes_idempotentes');
    }
};

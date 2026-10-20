<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trace des corrections et annulations d'opérations métier (réservation,
     * commande, livraison, récolte) : qui, quand, quelle transition, pourquoi.
     */
    public function up(): void
    {
        Schema::create('historique_operations', function (Blueprint $table) {
            $table->id();
            $table->string('sujet_type', 30);
            $table->unsignedBigInteger('sujet_id');
            $table->string('action', 40);
            $table->string('ancien_statut', 30)->nullable();
            $table->string('nouveau_statut', 30)->nullable();
            $table->json('details')->nullable();
            $table->string('motif', 500)->nullable();
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sujet_type', 'sujet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historique_operations');
    }
};

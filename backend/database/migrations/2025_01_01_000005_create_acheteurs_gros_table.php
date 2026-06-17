<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acheteurs_gros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('type_activite', 150)->nullable();
            $table->float('volume_achat_mensuel')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acheteurs_gros');
    }
};

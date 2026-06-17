<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transporteurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('type_vehicule', 100)->nullable();
            $table->float('capacite_charge')->nullable();
            $table->string('zone', 150)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transporteurs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producteurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->float('superficie')->nullable();
            $table->string('types_cultures', 255)->nullable();
            $table->string('region', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producteurs');
    }
};

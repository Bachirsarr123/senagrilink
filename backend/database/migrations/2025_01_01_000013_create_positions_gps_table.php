<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions_gps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transporteur_id')->constrained('transporteurs')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamp('timestamp')->useCurrent();

            $table->index(['transporteur_id', 'timestamp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions_gps');
    }
};

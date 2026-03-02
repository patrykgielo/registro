<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('car_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_brand_id')->constrained()->onDelete('cascade');
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->year('year_from')->nullable();
            $table->year('year_to')->nullable();
            $table->enum('status', ['pending', 'active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['car_brand_id', 'slug']);
            $table->index(['car_brand_id', 'status']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_models');
    }
};

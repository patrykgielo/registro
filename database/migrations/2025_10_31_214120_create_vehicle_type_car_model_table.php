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
        Schema::create('vehicle_type_car_model', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_type_id')->constrained()->onDelete('cascade');
            $table->foreignId('car_model_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['vehicle_type_id', 'car_model_id']);
            $table->index('vehicle_type_id');
            $table->index('car_model_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_type_car_model');
    }
};

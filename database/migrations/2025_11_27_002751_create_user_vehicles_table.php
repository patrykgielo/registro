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
        Schema::create('user_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('car_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('custom_brand', 100)->nullable();
            $table->string('custom_model', 100)->nullable();
            $table->year('year')->nullable();
            $table->string('nickname', 50)->nullable();
            $table->boolean('is_default')->default(true);
            $table->timestamps();

            $table->index('user_id', 'idx_user_vehicles_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_vehicles');
    }
};

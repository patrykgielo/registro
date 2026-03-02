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
        Schema::create('service_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('day_of_week'); // 0 = Sunday, 6 = Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            // Ensure no overlapping availability for same user/service/day
            $table->unique(['user_id', 'service_id', 'day_of_week', 'start_time'], 'sa_user_service_day_time_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_availabilities');
    }
};

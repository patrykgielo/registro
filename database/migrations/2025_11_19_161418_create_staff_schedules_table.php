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
        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->tinyInteger('day_of_week')->comment('0 = Sunday, 6 = Saturday');
            $table->time('start_time');
            $table->time('end_time');
            $table->date('effective_from')->nullable()->comment('When this schedule starts (null = always active)');
            $table->date('effective_until')->nullable()->comment('When this schedule ends (null = no end date)');
            $table->boolean('is_active')->default(true)->comment('Soft disable without deletion');
            $table->timestamps();

            // Indexes for performance
            $table->index(['user_id', 'day_of_week', 'is_active']);
            $table->index(['effective_from', 'effective_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_schedules');
    }
};

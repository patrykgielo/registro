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
        Schema::create('staff_date_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->date('exception_date');
            $table->enum('exception_type', ['unavailable', 'available'])->comment('unavailable = day off, available = working on normally free day');
            $table->time('start_time')->nullable()->comment('Null = all day exception');
            $table->time('end_time')->nullable()->comment('Null = all day exception');
            $table->text('reason')->nullable()->comment('Optional reason (e.g., "Doctor appointment", "Sick day")');
            $table->timestamps();

            // Indexes for performance
            $table->index(['user_id', 'exception_date']);
            $table->index('exception_date');

            // Unique constraint: prevent duplicate exceptions for same user on same date/time
            $table->unique(['user_id', 'exception_date', 'start_time', 'end_time'], 'staff_exceptions_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_date_exceptions');
    }
};

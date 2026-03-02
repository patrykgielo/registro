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
        Schema::create('staff_vacation_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason')->nullable()->comment('Optional reason (e.g., "Summer vacation", "Sick leave")');
            $table->boolean('is_approved')->default(false)->comment('Approval status (for future workflow)');
            $table->timestamps();

            // Indexes for performance
            $table->index(['user_id', 'start_date', 'end_date']);
            $table->index(['start_date', 'end_date']);

            // Validation constraint: end_date must be >= start_date (handled in model/form validation)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_vacation_periods');
    }
};

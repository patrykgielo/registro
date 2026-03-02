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
        Schema::create('sms_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique()->comment('Suppressed phone number in international format');
            $table->enum('reason', ['invalid_number', 'opted_out', 'failed_repeatedly', 'manual'])
                ->comment('Reason for suppression');
            $table->timestamp('suppressed_at')->comment('When phone number was suppressed');
            $table->timestamps();

            // Indexes
            $table->index('phone');
            $table->index('reason');
            $table->index('suppressed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_suppressions');
    }
};

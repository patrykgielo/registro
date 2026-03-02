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
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->unique()->comment('Suppressed email address');
            $table->enum('reason', ['bounced', 'complained', 'unsubscribed', 'manual'])
                ->comment('Reason for suppression');
            $table->timestamp('suppressed_at')->comment('When email was suppressed');
            $table->timestamps();

            // Indexes
            $table->index('email');
            $table->index('reason');
            $table->index('suppressed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the user_consents table for GDPR consent audit trail.
     * Records every consent grant/revoke with timestamp, IP, and user agent.
     */
    public function up(): void
    {
        Schema::create('user_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('consent_type'); // sms, email_marketing, terms_accepted, etc.
            $table->string('action'); // granted, revoked
            $table->string('source')->nullable(); // profile, booking, registration
            $table->string('consent_version')->nullable(); // Version of terms/policy
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable(); // Additional context
            $table->timestamps();

            $table->index('consent_type');
            $table->index('action');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_consents');
    }
};

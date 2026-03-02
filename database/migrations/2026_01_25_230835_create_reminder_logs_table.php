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
        Schema::create('reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->onDelete('cascade');
            $table->foreignId('reminder_config_id')->constrained()->onDelete('cascade');
            $table->enum('channel', ['sms', 'email']);
            $table->string('message_key', 64)->unique(); // MD5 hash for idempotency
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->string('external_id')->nullable(); // Provider's message ID
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['appointment_id', 'reminder_config_id']);
            $table->index(['channel', 'status']);
            $table->index('sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_logs');
    }
};

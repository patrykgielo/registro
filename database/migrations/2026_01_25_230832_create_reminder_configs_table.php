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
        Schema::create('reminder_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Human readable: "24h SMS Reminder"
            $table->enum('channel', ['sms', 'email']);
            $table->enum('trigger_type', ['before', 'after'])->default('before');
            $table->unsignedInteger('trigger_hours')->default(24);
            $table->unsignedInteger('trigger_minutes')->default(0);
            $table->unsignedInteger('window_buffer_minutes')->default(60); // Time window buffer for scheduler
            $table->string('template_key'); // References sms_templates.key or email template
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable(); // Additional config (conditions, filters)
            $table->unsignedInteger('priority')->default(0); // Execution order
            $table->timestamps();

            $table->index(['enabled', 'channel']);
            $table->index(['trigger_type', 'trigger_hours']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_configs');
    }
};

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
        Schema::create('sms_sends', function (Blueprint $table) {
            $table->id();
            $table->string('template_key', 255)->comment('FK to sms_templates.key');
            $table->string('language', 2)->comment('Language code: pl, en');
            $table->string('phone_to', 20)->comment('Recipient phone number in international format (+48...)');
            $table->text('message_body')->comment('Rendered SMS message content');
            $table->enum('status', ['pending', 'sent', 'failed', 'invalid_number'])
                ->default('pending')
                ->comment('SMS delivery status');
            $table->timestamp('sent_at')->nullable()->comment('When SMS was sent');
            $table->json('metadata')->nullable()->comment('User ID, appointment ID, etc.');
            $table->string('message_key', 255)->unique()->comment('Idempotency key');
            $table->text('error_message')->nullable()->comment('Error details if failed');
            $table->string('sms_id', 100)->nullable()->comment('SMSAPI message ID for tracking');
            $table->integer('message_length')->nullable()->comment('SMS character length');
            $table->integer('message_parts')->nullable()->comment('Number of SMS parts (multi-part messages)');
            $table->timestamps();

            // Indexes for query performance
            $table->index('template_key');
            $table->index('status');
            $table->index('phone_to');
            $table->index('sent_at');
            $table->index('message_key');
            $table->index('sms_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_sends');
    }
};

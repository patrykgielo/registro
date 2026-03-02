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
        Schema::create('email_sends', function (Blueprint $table) {
            $table->id();
            $table->string('template_key', 255)->comment('FK to email_templates.key');
            $table->string('language', 2)->comment('Language code: pl, en');
            $table->string('recipient_email', 255)->comment('Recipient email address');
            $table->string('subject', 255)->comment('Rendered email subject');
            $table->longText('body_html')->comment('Rendered HTML body');
            $table->text('body_text')->nullable()->comment('Rendered plain text body');
            $table->enum('status', ['pending', 'sent', 'failed', 'bounced'])
                ->default('pending')
                ->comment('Email delivery status');
            $table->timestamp('sent_at')->nullable()->comment('When email was sent');
            $table->json('metadata')->nullable()->comment('User ID, appointment ID, etc.');
            $table->string('message_key', 255)->unique()->comment('Idempotency key');
            $table->text('error_message')->nullable()->comment('Error details if failed');
            $table->timestamps();

            // Indexes for query performance
            $table->index('template_key');
            $table->index('status');
            $table->index('recipient_email');
            $table->index('sent_at');
            $table->index('message_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_sends');
    }
};

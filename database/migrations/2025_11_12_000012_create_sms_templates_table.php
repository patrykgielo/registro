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
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 255)->comment('Template identifier (e.g., appointment-reminder-24h)');
            $table->string('language', 2)->comment('Language code: pl, en');
            $table->text('message_body')->comment('SMS message template with {{placeholders}}');
            $table->json('variables')->comment('Available variables for template');
            $table->integer('max_length')->default(160)->comment('Max SMS length (160 GSM, 70 Unicode)');
            $table->boolean('active')->default(true)->comment('Template is active');
            $table->timestamps();

            // Indexes and constraints
            $table->index('key');
            $table->index('language');
            $table->unique(['key', 'language'], 'sms_templates_key_language_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};

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
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 255)->comment('Template identifier (e.g., user-registered)');
            $table->string('language', 2)->comment('Language code: pl, en');
            $table->string('subject', 255)->comment('Email subject with {{placeholders}}');
            $table->text('html_body')->comment('HTML template content with Blade syntax');
            $table->text('text_body')->nullable()->comment('Plain text version');
            $table->string('blade_path', 255)->nullable()->comment('Fallback Blade file path');
            $table->json('variables')->comment('Available variables for template');
            $table->boolean('active')->default(true)->comment('Template is active');
            $table->timestamps();

            // Indexes and constraints
            $table->index('key');
            $table->index('language');
            $table->unique(['key', 'language'], 'email_templates_key_language_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};

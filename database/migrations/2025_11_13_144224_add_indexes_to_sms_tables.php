<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds performance indexes to SMS-related tables for:
     * - Faster query performance (status + sent_at lookups)
     * - Efficient cleanup jobs (created_at)
     * - Quick template lookups (key + language + active)
     * - Fast suppression checks (phone + suppressed)
     */
    public function up(): void
    {
        // SMS Sends - Frequently queried together
        Schema::table('sms_sends', function (Blueprint $table) {
            // Composite index for status-based queries with date filtering
            // Used in: dashboard stats, failure reports, admin filtering
            $table->index(['status', 'sent_at'], 'idx_sms_sends_status_sent_at');

            // Index for cleanup job (GDPR 90-day retention)
            // Used in: CleanupOldSmsLogsJob
            $table->index('created_at', 'idx_sms_sends_created_at');
        });

        // SMS Templates - Template lookup optimization
        Schema::table('sms_templates', function (Blueprint $table) {
            // Composite index for active template lookups by key and language
            // Used in: SmsService::sendFromTemplate() - most critical query
            $table->index(['key', 'language', 'active'], 'idx_sms_templates_lookup');
        });

        // SMS Suppressions - Fast suppression checks
        // Note: 'phone' column already has unique index, no additional index needed
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop indexes in reverse order
        Schema::table('sms_templates', function (Blueprint $table) {
            $table->dropIndex('idx_sms_templates_lookup');
        });

        Schema::table('sms_sends', function (Blueprint $table) {
            $table->dropIndex('idx_sms_sends_status_sent_at');
            $table->dropIndex('idx_sms_sends_created_at');
        });
    }
};
